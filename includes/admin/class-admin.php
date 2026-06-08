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
			case 'reset_stats':
				PBD_Exp_Admin_Edit::handle_reset_stats();
				break;
			case 'transition_status':
				PBD_Exp_Admin_Edit::handle_transition();
				break;
			case 'clone_experiment':
				PBD_Exp_Admin_Edit::handle_clone();
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
		$all_active_set = PBD_Exp_Repo::list_experiments( array( 'draft', 'active', 'paused' ) );

		$counts = array(
			'all'    => count( $all_active_set ),
			'active' => 0,
			'paused' => 0,
			'draft'  => 0,
		);
		foreach ( $all_active_set as $e ) {
			if ( isset( $counts[ $e['status'] ] ) ) {
				$counts[ $e['status'] ]++;
			}
		}

		$filter      = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$valid       = array( 'all', 'active', 'paused', 'draft' );
		if ( ! in_array( $filter, $valid, true ) ) {
			$filter = 'all';
		}
		$experiments = 'all' === $filter
			? $all_active_set
			: array_values( array_filter( $all_active_set, function ( $e ) use ( $filter ) { return $e['status'] === $filter; } ) );

		$message  = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		$base_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		?>
		<div class="wrap pbd-exp-wrap">
			<h1 class="wp-heading-inline">Experiments</h1>
			<a class="page-title-action" href="<?php echo esc_url( $base_url . '&action=new' ); ?>">Add New</a>
			<hr class="wp-header-end">

			<?php $this->render_flash_notice( $message ); ?>

			<?php if ( 0 === $counts['all'] ) : ?>
				<?php $this->render_first_run_hero(); ?>
			<?php else : ?>
				<div class="pbd-exp-filter-bar">
					<?php
					$tabs = array(
						'all'    => 'All',
						'active' => 'Active',
						'paused' => 'Paused',
						'draft'  => 'Drafts',
					);
					foreach ( $tabs as $key => $label ) :
						$is_active = $filter === $key;
						$url = 'all' === $key ? $base_url : $base_url . '&filter=' . $key;
						?>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $is_active ? 'is-active' : ''; ?>">
							<?php echo esc_html( $label ); ?>
							<span class="count"><?php echo (int) $counts[ $key ]; ?></span>
						</a>
					<?php endforeach; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-archive' ) ); ?>" style="margin-left:auto;">Past experiments &rarr;</a>
				</div>

				<?php if ( empty( $experiments ) ) : ?>
					<div class="pbd-exp-empty">
						<h3>No <?php echo esc_html( strtolower( $tabs[ $filter ] ) ); ?> experiments</h3>
						<p>Switch filters above, or <a href="<?php echo esc_url( $base_url . '&action=new' ); ?>">add a new one</a>.</p>
					</div>
				<?php else : ?>
					<table class="widefat striped pbd-exp-list-table">
						<thead>
							<tr>
								<th class="col-name">Experiment</th>
								<th>Status</th>
								<th class="col-target">Target</th>
								<th class="col-variants">Variants</th>
								<th class="col-traffic">Visitors</th>
								<th class="col-actions">&nbsp;</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $experiments as $experiment ) :
								$variants = PBD_Exp_Repo::get_variants( $experiment['id'] );
								$variant_summary = array();
								foreach ( $variants as $v ) {
									$variant_summary[] = esc_html( $v['label'] );
								}
								$visitors = PBD_Exp_Repo::count_assignments(
									(int) $experiment['id'],
									null,
									! empty( $experiment['started_at'] ) ? $experiment['started_at'] : null,
									null
								);
								$dashboard_url = $base_url . '&action=dashboard&id=' . $experiment['id'];
								$edit_url      = $base_url . '&action=edit&id=' . $experiment['id'];
							?>
								<tr>
									<td class="col-name">
										<a href="<?php echo esc_url( $dashboard_url ); ?>" class="row-link"><strong><?php echo esc_html( $experiment['name'] ); ?></strong></a>
										<span class="pbd-exp-key"><code><?php echo esc_html( $experiment['experiment_key'] ); ?></code></span>
									</td>
									<td>
										<span class="pbd-exp-status pbd-exp-status--<?php echo esc_attr( $experiment['status'] ); ?>"><?php echo esc_html( ucfirst( $experiment['status'] ) ); ?></span>
									</td>
									<td class="col-target"><code><?php echo esc_html( $experiment['target_path'] ); ?></code></td>
									<td class="col-variants"><?php echo wp_kses_post( implode( ' &middot; ', $variant_summary ) ); ?></td>
									<td class="col-traffic">
										<strong><?php echo esc_html( number_format_i18n( $visitors ) ); ?></strong>
										<?php if ( 'draft' === $experiment['status'] ) : ?>
											<span style="color:#8c8f94;font-size:12px;"> (not started)</span>
										<?php endif; ?>
									</td>
									<td class="col-actions">
										<a class="button button-small" href="<?php echo esc_url( $dashboard_url ); ?>">Dashboard</a>
										<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">Edit</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<details class="pbd-exp-events-wrap">
				<summary><h2>Recent activity <span class="hint">last 50 events across all experiments</span></h2></summary>
				<?php $this->render_recent_events(); ?>
			</details>
		</div>
		<?php
	}

	private function render_flash_notice( $message ) {
		switch ( $message ) {
			case 'saved':
				echo '<div class="notice notice-success is-dismissible"><p>Experiment saved.</p></div>';
				break;
			case 'deleted':
				echo '<div class="notice notice-success is-dismissible"><p>Experiment deleted.</p></div>';
				break;
			case 'concluded':
				echo '<div class="notice notice-success is-dismissible"><p>Experiment concluded. Snapshot frozen and moved to Past Experiments.</p></div>';
				break;
			case 'started':
				echo '<div class="notice notice-success is-dismissible"><p>Experiment started. Visitors will be assigned variants on the next page load.</p></div>';
				break;
			case 'paused':
				echo '<div class="notice notice-success is-dismissible"><p>Experiment paused. Existing assignments are preserved.</p></div>';
				break;
			case 'resumed':
				echo '<div class="notice notice-success is-dismissible"><p>Experiment resumed.</p></div>';
				break;
			case 'invalid_transition':
				echo '<div class="notice notice-error is-dismissible"><p>That status transition is not allowed.</p></div>';
				break;
			case 'missing':
				echo '<div class="notice notice-error is-dismissible"><p>ID and name are required.</p></div>';
				break;
			case 'cloned':
				echo '<div class="notice notice-success is-dismissible"><p>Experiment cloned. Edit and start when ready.</p></div>';
				break;
		}
	}

	private function render_first_run_hero() {
		$new_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=new' );
		?>
		<div class="pbd-exp-hero">
			<h2>Run your first split test</h2>
			<p>PBD Experiments assigns visitors to variants of a page, records assignments and conversion events, and reports the results. Each experiment targets a single URL path on this site.</p>

			<div class="pbd-exp-hero__steps">
				<div class="pbd-exp-hero__step">
					<strong><span class="pbd-exp-hero__step-num">1</span>Create the experiment</strong>
					<span>Pick the target path, name your variants, and choose whether each one swaps the template or redirects to another URL.</span>
				</div>
				<div class="pbd-exp-hero__step">
					<strong><span class="pbd-exp-hero__step-num">2</span>Add a conversion metric</strong>
					<span>Fire a JS event, drop the shortcode, or add <code>data-pbd-exp</code>/<code>data-pbd-event</code> attributes to a form. Each metric is one event name.</span>
				</div>
				<div class="pbd-exp-hero__step">
					<strong><span class="pbd-exp-hero__step-num">3</span>Start it and watch the dashboard</strong>
					<span>Move it from Draft to Active. Visitors get a sticky variant via cookie, results land on the dashboard live, and you conclude when ready.</span>
				</div>
			</div>

			<a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary button-hero">Create your first experiment</a>
		</div>
		<?php
	}

	private function render_recent_events() {
		$events = PBD_Exp_Repo::recent_events( 50 );
		if ( empty( $events ) ) {
			?>
			<div class="pbd-exp-empty">
				<h3>No events recorded yet</h3>
				<p>Once an active experiment starts firing conversion events from the page, the most recent 50 will appear here so you can confirm tracking is wired up correctly.</p>
			</div>
			<?php
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
