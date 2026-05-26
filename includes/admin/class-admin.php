<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin shell. Owns the menu, the experiment list page, and dispatches to
 * Edit / Dashboard / Archive sub-screens.
 */
final class PBD_Exp_Admin {

	const MENU_SLUG = 'pbd-experiments';
	const NONCE_ACTION = 'pbd_exp_save';

	public function hook() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	public function menu() {
		add_menu_page(
			'Experiments',
			'Experiments',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-chart-area',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Past Experiments',
			'Past Experiments',
			'manage_options',
			self::MENU_SLUG . '-archive',
			array( $this, 'render_archive' )
		);
	}

	public function maybe_enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'pbd-exp-admin',
			PBD_EXP_URL . 'assets/admin.css',
			array(),
			PBD_EXP_VERSION
		);
		wp_enqueue_script(
			'pbd-exp-admin',
			PBD_EXP_URL . 'assets/admin.js',
			array( 'jquery-ui-sortable' ),
			PBD_EXP_VERSION,
			true
		);
	}

	public function maybe_handle_post() {
		if ( empty( $_POST['pbd_exp_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage experiments.', 'pbd-experiments' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$action = sanitize_key( $_POST['pbd_exp_action'] );
		switch ( $action ) {
			case 'save_experiment':
				PBD_Exp_Admin_Edit::handle_save();
				break;
			case 'delete_experiment':
				PBD_Exp_Admin_Edit::handle_delete();
				break;
			case 'transition_status':
				PBD_Exp_Admin_Edit::handle_transition();
				break;
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( 'edit' === $action || 'new' === $action ) {
			PBD_Exp_Admin_Edit::render( $id );
			return;
		}

		if ( 'dashboard' === $action && $id ) {
			PBD_Exp_Admin_Dashboard::render( $id );
			return;
		}

		$this->render_list();
	}

	public function render_archive() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		PBD_Exp_Admin_Archive::render();
	}

	private function render_list() {
		$experiments = PBD_Exp_Repo::list_experiments( array( 'draft', 'active', 'paused' ) );
		$message     = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';

		?>
		<div class="wrap pbd-exp-wrap">
			<h1 class="wp-heading-inline">Experiments</h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=new' ) ); ?>">Add New</a>
			<hr class="wp-header-end">

			<?php if ( 'saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>Experiment saved.</p></div>
			<?php elseif ( 'deleted' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>Experiment deleted.</p></div>
			<?php elseif ( 'concluded' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>Experiment concluded. Snapshot frozen and moved to Past Experiments.</p></div>
			<?php elseif ( 'invalid_transition' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p>That status transition is not allowed.</p></div>
			<?php elseif ( 'missing' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p>Key and name are required.</p></div>
			<?php endif; ?>

			<?php if ( empty( $experiments ) ) : ?>
				<div class="notice notice-info"><p>No experiments yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=new' ) ); ?>">Create your first one.</a></p></div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Name</th>
							<th>Key</th>
							<th>Status</th>
							<th>Target</th>
							<th>Variants</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $experiments as $experiment ) :
							$variants = PBD_Exp_Repo::get_variants( $experiment['id'] );
							$variant_summary = array();
							foreach ( $variants as $v ) {
								$variant_summary[] = $v['label'] . ' (' . $v['weight'] . ')';
							}
						?>
							<tr>
								<td><strong><?php echo esc_html( $experiment['name'] ); ?></strong></td>
								<td><code><?php echo esc_html( $experiment['experiment_key'] ); ?></code></td>
								<td><span class="pbd-exp-status pbd-exp-status--<?php echo esc_attr( $experiment['status'] ); ?>"><?php echo esc_html( ucfirst( $experiment['status'] ) ); ?></span></td>
								<td><code><?php echo esc_html( $experiment['target_path'] ); ?></code></td>
								<td><?php echo esc_html( implode( ', ', $variant_summary ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=dashboard&id=' . $experiment['id'] ) ); ?>">Dashboard</a> |
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=edit&id=' . $experiment['id'] ) ); ?>">Edit</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top:32px;">Recent Events</h2>
			<?php $this->render_recent_events(); ?>
		</div>
		<?php
	}

	private function render_recent_events() {
		$events = PBD_Exp_Repo::recent_events( 50 );
		if ( empty( $events ) ) {
			echo '<p>No events recorded yet.</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Time</th>
					<th>Experiment</th>
					<th>Variant</th>
					<th>Event</th>
					<th>URL</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( $event['occurred_at'] ); ?></td>
						<td><code><?php echo esc_html( $event['experiment_key'] ); ?></code></td>
						<td><?php echo esc_html( $event['variant_key'] ? $event['variant_key'] : 'unassigned' ); ?></td>
						<td><code><?php echo esc_html( $event['event_name'] ); ?></code></td>
						<td><?php echo esc_html( wp_trim_words( $event['url'], 12, '...' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
