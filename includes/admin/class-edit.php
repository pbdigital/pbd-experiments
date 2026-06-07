<?php
defined( 'ABSPATH' ) || exit;

/**
 * Experiment Edit screen: real form UI for experiment, variants, metrics.
 *
 * Status workflow (locked):
 *   draft    -> active | paused | (delete)
 *   active   -> paused | concluded
 *   paused   -> active | concluded
 *   concluded-> (terminal; clone to re-run)
 */
final class PBD_Exp_Admin_Edit {

	const ALLOWED_TRANSITIONS = array(
		'draft'     => array( 'active', 'paused' ),
		'active'    => array( 'paused', 'concluded' ),
		'paused'   => array( 'active', 'concluded' ),
		'concluded' => array(),
	);

	public static function render( $id = 0 ) {
		$experiment = $id ? PBD_Exp_Repo::get_experiment( $id ) : null;
		$variants   = $experiment ? PBD_Exp_Repo::get_variants( $experiment['id'] ) : array();
		$metrics    = $experiment ? PBD_Exp_Repo::get_metrics( $experiment['id'] ) : array();

		$is_new = ! $experiment;

		if ( ! $experiment ) {
			$experiment = array(
				'id'                 => 0,
				'experiment_key'     => '',
				'name'               => '',
				'status'             => 'draft',
				'target_path'        => '/',
				'cookie_days'        => 90,
				'include_logged_in'  => 0,
				'winning_variant_id' => null,
				'notes'              => '',
			);
		}

		if ( empty( $variants ) ) {
			$variants = array(
				array( 'id' => 0, 'variant_key' => 'control', 'label' => 'Control', 'weight' => 50, 'variant_type' => 'template', 'template_path' => '', 'redirect_url' => '', 'sort_order' => 0 ),
				array( 'id' => 0, 'variant_key' => 'variant_b', 'label' => 'Variant B', 'weight' => 50, 'variant_type' => 'template', 'template_path' => '', 'redirect_url' => '', 'sort_order' => 1 ),
			);
		}

		if ( empty( $metrics ) ) {
			$metrics = array(
				array( 'id' => 0, 'metric_key' => 'opt_in', 'name' => 'Opt-in', 'event_name' => 'opt_in', 'active' => 1, 'sort_order' => 0 ),
			);
		}

		$is_locked = 'concluded' === $experiment['status'];
		$is_concluded = $is_locked;

		$site_origin   = untrailingslashit( home_url( '/' ) );
		$pages         = self::list_target_pages();
		$page_templates = self::get_page_template_choices();

		$cancel_url = admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG );
		?>
		<div class="wrap pbd-exp-wrap pbd-exp-edit-screen <?php echo $is_concluded ? 'pbd-exp-edit-screen--concluded' : ''; ?>">
			<h1 class="wp-heading-inline">
				<?php echo $is_new ? 'Add Experiment' : 'Edit experiment: ' . esc_html( $experiment['name'] ); ?>
			</h1>
			<?php if ( $experiment['id'] ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=dashboard&id=' . $experiment['id'] ) ); ?>">View dashboard</a>
			<?php endif; ?>
			<?php if ( $experiment['id'] && $is_concluded ) : ?>
				<?php self::render_clone_inline_button( $experiment ); ?>
			<?php endif; ?>
			<a class="page-title-action" href="<?php echo esc_url( $cancel_url ); ?>">&larr; All experiments</a>
			<hr class="wp-header-end">

			<?php if ( $is_new ) : ?>
				<p class="pbd-exp-page-lede">Name it, pick where it runs, define variants, then save.</p>
			<?php endif; ?>

			<?php
			$flash = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
			$messages = array(
				'saved'              => array( 'success', 'Changes saved.' ),
				'started'            => array( 'success', 'Experiment started. Visitors will be assigned variants on the next page load.' ),
				'paused'             => array( 'success', 'Experiment paused. Existing assignments are preserved.' ),
				'resumed'            => array( 'success', 'Experiment resumed.' ),
				'invalid_transition' => array( 'error', 'That status transition is not allowed.' ),
				'cloned'             => array( 'success', 'Experiment cloned. Edit and start when ready.' ),
			);
			if ( isset( $messages[ $flash ] ) ) {
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $flash ][0] ), esc_html( $messages[ $flash ][1] ) );
			}
			?>

			<?php if ( $is_concluded ) : ?>
				<?php self::render_concluded_header( $experiment, $variants ); ?>
			<?php endif; ?>

			<?php if ( $experiment['id'] && ! $is_concluded ) : ?>
				<?php self::render_status_bar( $experiment ); ?>
			<?php elseif ( ! $is_concluded ) : ?>
				<p class="pbd-exp-status-placeholder">Status appears here after you save.</p>
			<?php endif; ?>

			<?php if ( $is_concluded ) : ?>
				<details class="pbd-exp-locked-config">
					<summary>Configuration (locked) &mdash; click to view what was tested</summary>
			<?php endif; ?>

			<form method="post" id="pbd-exp-form" novalidate>
				<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
				<input type="hidden" name="pbd_exp_action" value="save_experiment">
				<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $experiment['id'] ); ?>">

				<div class="pbd-exp-validation-summary" id="pbd-exp-validation" hidden>
					<strong>Please fix these before saving:</strong>
					<ul></ul>
				</div>

				<div class="pbd-exp-card" id="pbd-exp-basics">
					<div class="pbd-exp-card__body pbd-exp-stack">
						<div class="pbd-exp-field">
							<label for="name" class="pbd-exp-field__label">Name <span class="pbd-exp-req">*</span></label>
							<input type="text" class="regular-text pbd-exp-input" id="name" name="name" value="<?php echo esc_attr( $experiment['name'] ); ?>" <?php disabled( $is_locked ); ?> placeholder="Free classes homepage test" required>
							<?php if ( $is_new ) : ?>
								<div class="pbd-exp-key-badge" id="pbd-exp-key-badge" hidden>
									ID: <code id="pbd-exp-key-preview"></code>
									<button type="button" class="pbd-exp-link-btn" id="pbd-exp-key-edit">edit</button>
								</div>
							<?php endif; ?>
							<div class="pbd-exp-field" id="pbd-exp-key-field" <?php echo $is_new ? 'hidden' : ''; ?> style="margin-top:10px;">
								<label for="experiment_key" class="pbd-exp-field__label">ID</label>
								<input type="text" class="regular-text pbd-exp-input" id="experiment_key" name="experiment_key" value="<?php echo esc_attr( $experiment['experiment_key'] ); ?>" <?php wp_readonly( (bool) $experiment['id'] ); ?> <?php disabled( $is_locked ); ?> placeholder="free_classes_homepage" data-auto-from="#name">
								<p class="pbd-exp-field__hint">ID used by the tracking code. Auto-fills from the name. You usually won't need to touch this.</p>
							</div>
						</div>

						<div class="pbd-exp-field">
							<label for="target_path" class="pbd-exp-field__label">Target URL <span class="pbd-exp-req">*</span></label>
							<div class="pbd-exp-url-input">
								<span class="pbd-exp-url-input__origin" title="<?php echo esc_attr( $site_origin ); ?>"><?php echo esc_html( $site_origin ); ?></span>
								<input class="pbd-exp-url-input__path" id="target_path" name="target_path" value="<?php echo esc_attr( $experiment['target_path'] ); ?>" <?php disabled( $is_locked ); ?> placeholder="/free-classes" list="pbd-exp-page-list" autocomplete="off" required>
							</div>
							<?php if ( ! empty( $pages ) ) : ?>
								<datalist id="pbd-exp-page-list">
									<?php foreach ( $pages as $p ) : ?>
										<option value="<?php echo esc_attr( $p['path'] ); ?>"><?php echo esc_html( $p['title'] ); ?></option>
									<?php endforeach; ?>
								</datalist>
								<p class="pbd-exp-field__hint">Type or pick from the dropdown. Use <code>/</code> for the homepage.</p>
							<?php else : ?>
								<p class="pbd-exp-field__hint">Site-root-relative path. Use <code>/</code> for the homepage.</p>
							<?php endif; ?>
						</div>

						<div class="pbd-exp-field pbd-exp-field--inline">
							<label for="cookie_days" class="pbd-exp-field__label">Remember a visitor's variant for</label>
							<div class="pbd-exp-inline-row">
								<input class="small-text pbd-exp-input" id="cookie_days" name="cookie_days" type="number" min="1" value="<?php echo esc_attr( $experiment['cookie_days'] ); ?>" <?php disabled( $is_locked ); ?>>
								<span class="pbd-exp-helper-text">days</span>
								<span class="pbd-exp-helper-text" id="pbd-exp-cookie-helper"></span>
							</div>
							<p class="pbd-exp-field__hint">How long a returning visitor keeps the same variant.</p>
						</div>

						<div class="pbd-exp-field">
							<label class="pbd-exp-checkbox">
								<input type="checkbox" id="include_logged_in" name="include_logged_in" value="1" <?php checked( ! empty( $experiment['include_logged_in'] ) ); ?> <?php disabled( $is_locked ); ?>>
								Include logged-in users in this experiment
							</label>
							<div class="pbd-exp-warning-note" id="pbd-exp-loggedin-warning" <?php if ( empty( $experiment['include_logged_in'] ) ) echo 'hidden'; ?>>
								<strong>Heads-up:</strong> your own admin sessions will now be counted in results.
							</div>
						</div>

						<div class="pbd-exp-field">
							<label for="notes" class="pbd-exp-field__label">Notes</label>
							<textarea id="notes" name="notes" rows="3" class="large-text pbd-exp-input" <?php disabled( $is_locked ); ?> placeholder="Optional. Why are you running this test? What do you hope happens? Future-you will thank you."><?php echo esc_textarea( isset( $experiment['notes'] ) ? $experiment['notes'] : '' ); ?></textarea>
							<p class="pbd-exp-field__hint">Optional. Shown on the dashboard and archive.</p>
						</div>
					</div>
				</div>

				<div class="pbd-exp-card">
					<div class="pbd-exp-card__header">
						<h2>What's different in each variant?</h2>
						<p class="pbd-exp-card__hint">The first row is the original page (your Target URL). Add variants below to change what some visitors see.</p>
						<button type="button" class="pbd-exp-link-btn pbd-exp-link-btn--header" id="pbd-exp-toggle-variant-keys" aria-pressed="false">Show technical IDs</button>
					</div>
					<div class="pbd-exp-card__body">
						<?php self::render_variants_table( $variants, $is_locked, $experiment['target_path'], $page_templates ); ?>
					</div>
				</div>

				<div class="pbd-exp-card">
					<div class="pbd-exp-card__header">
						<h2>What counts as a conversion?</h2>
						<p class="pbd-exp-card__hint">Each metric is something the page does when a visitor converts (submits a form, clicks a button, lands on a thank-you page). For each metric, pick what triggers it and we'll show you exactly what to paste.</p>
						<button type="button" class="pbd-exp-link-btn pbd-exp-link-btn--header" id="pbd-exp-toggle-metric-keys" aria-pressed="false">Show technical IDs</button>
					</div>
					<div class="pbd-exp-card__body">
						<?php self::render_metrics_table( $metrics, $is_locked, $experiment ); ?>
					</div>
				</div>

				<?php if ( $experiment['id'] && $is_concluded ) : ?>
					<div class="pbd-exp-card">
						<div class="pbd-exp-card__header">
							<h2>Snapshot</h2>
							<p class="pbd-exp-card__hint">Numbers were frozen when you concluded the experiment.</p>
						</div>
						<div class="pbd-exp-card__body">
							<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=dashboard&id=' . $experiment['id'] ) ); ?>">Open dashboard</a></p>
						</div>
					</div>
				<?php endif; ?>

				<div class="pbd-exp-save-bar-spacer" aria-hidden="true"></div>

				<?php if ( ! $is_locked ) : ?>
					<div class="pbd-exp-save-bar" role="region" aria-label="Save actions">
						<a class="pbd-exp-save-bar__cancel" href="<?php echo esc_url( $cancel_url ); ?>">Cancel</a>
						<span class="pbd-exp-save-bar__hint" id="pbd-exp-save-hint"></span>
						<button type="submit" class="button button-primary button-large pbd-exp-save-bar__save">
							<?php echo $is_new ? 'Save draft' : 'Save changes'; ?>
						</button>
					</div>
				<?php endif; ?>
			</form>

			<?php if ( $is_concluded ) : ?>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Returns a list of published pages and posts as { path, title } for the target picker.
	 * Capped to 200 entries to keep the datalist sane.
	 */
	private static function list_target_pages() {
		$out = array( array( 'path' => '/', 'title' => 'Home' ) );

		$query = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		) );

		foreach ( $query as $pid ) {
			$link = get_permalink( $pid );
			if ( ! $link ) {
				continue;
			}
			$parts = wp_parse_url( $link );
			$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
			$path  = untrailingslashit( $path );
			if ( '' === $path ) {
				$path = '/';
			}
			$out[] = array(
				'path'  => $path,
				'title' => get_the_title( $pid ),
			);
		}

		return $out;
	}

	/**
	 * Returns theme page templates as filename => name, including a sentinel for "Default".
	 */
	private static function get_page_template_choices() {
		$choices = array();
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( $theme && method_exists( $theme, 'get_page_templates' ) ) {
				$templates = $theme->get_page_templates( null, 'page' );
				if ( is_array( $templates ) ) {
					foreach ( $templates as $filename => $name ) {
						$choices[ $filename ] = $name;
					}
				}
			}
		}
		return $choices;
	}

	private static function render_concluded_header( $experiment, $variants ) {
		$id = (int) $experiment['id'];
		$winner = (int) $experiment['winning_variant_id'];
		$dashboard_url = admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=dashboard&id=' . $id );
		?>
		<div class="pbd-exp-concluded-header">
			<div class="pbd-exp-concluded-header__row">
				<span class="pbd-exp-status pbd-exp-status--concluded">Concluded</span>
				<?php if ( ! empty( $experiment['concluded_at'] ) ) : ?>
					<span class="pbd-exp-concluded-header__meta">on <?php echo esc_html( mysql2date( get_option( 'date_format' ), $experiment['concluded_at'] ) ); ?></span>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( $dashboard_url ); ?>">View snapshot</a>
				<?php self::render_clone_inline_button( $experiment ); ?>
				<span class="pbd-exp-concluded-header__spacer"></span>
				<?php self::render_concluded_delete( $experiment ); ?>
			</div>

			<form method="post" class="pbd-exp-winner-form">
				<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
				<input type="hidden" name="pbd_exp_action" value="save_experiment">
				<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $id ); ?>">
				<label for="winning_variant_id"><strong>Declare winner</strong></label>
				<select id="winning_variant_id" name="winning_variant_id">
					<option value="">(none declared)</option>
					<?php foreach ( $variants as $v ) : ?>
						<option value="<?php echo esc_attr( $v['id'] ); ?>" <?php selected( $winner, (int) $v['id'] ); ?>><?php echo esc_html( $v['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary">Save winner</button>
				<span class="pbd-exp-concluded-header__hint">Self-declared. No automatic significance calculation.</span>
			</form>
		</div>
		<?php
	}

	private static function render_clone_inline_button( $experiment ) {
		$id = (int) $experiment['id'];
		?>
		<form method="post" class="pbd-exp-clone-form" style="display:inline;">
			<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
			<input type="hidden" name="pbd_exp_action" value="clone_experiment">
			<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $id ); ?>">
			<button type="submit" class="page-title-action">Clone as new experiment</button>
		</form>
		<?php
	}

	private static function render_concluded_delete( $experiment ) {
		$id = (int) $experiment['id'];
		$key = $experiment['experiment_key'];
		$prompt = sprintf(
			'Removing this concluded experiment deletes its snapshot and historical events permanently. Type the experiment ID "%s" to confirm.',
			$key
		);
		?>
		<form method="post" class="pbd-exp-delete-form" data-confirm-key="<?php echo esc_attr( $key ); ?>" data-confirm-prompt="<?php echo esc_attr( $prompt ); ?>">
			<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
			<input type="hidden" name="pbd_exp_action" value="delete_experiment">
			<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $id ); ?>">
			<button type="submit" class="button pbd-exp-btn-danger">Remove from history&hellip;</button>
		</form>
		<?php
	}

	private static function render_status_bar( $experiment ) {
		$id      = (int) $experiment['id'];
		$status  = $experiment['status'];
		$allowed = self::ALLOWED_TRANSITIONS[ $status ] ?? array();
		?>
		<div class="pbd-exp-status-bar">
			<span class="pbd-exp-status-bar__label">Status</span>
			<span class="pbd-exp-status pbd-exp-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>

			<?php if ( 'draft' === $status ) : ?>
				<?php self::transition_button( $id, 'active', 'Start experiment', 'button-primary' ); ?>
			<?php endif; ?>

			<?php if ( 'active' === $status ) : ?>
				<?php self::transition_button( $id, 'paused', 'Pause', 'button' ); ?>
				<?php self::transition_button( $id, 'concluded', 'Conclude experiment', 'button-primary pbd-exp-btn-conclude', 'Concluding freezes the result snapshot and moves the experiment to Past Experiments. Continue?' ); ?>
			<?php endif; ?>

			<?php if ( 'paused' === $status ) : ?>
				<?php self::transition_button( $id, 'active', 'Resume', 'button-primary' ); ?>
				<?php self::transition_button( $id, 'concluded', 'Conclude experiment', 'button pbd-exp-btn-conclude', 'Concluding freezes the result snapshot and moves the experiment to Past Experiments. Continue?' ); ?>
			<?php endif; ?>

			<span class="spacer"></span>

			<?php if ( 'draft' === $status ) : ?>
				<form method="post" style="margin:0;" onsubmit="return confirm('Permanently delete this draft experiment? This cannot be undone.');">
					<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
					<input type="hidden" name="pbd_exp_action" value="delete_experiment">
					<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $id ); ?>">
					<button type="submit" class="button pbd-exp-btn-danger">Delete</button>
				</form>
			<?php endif; ?>

			<p class="pbd-exp-hint">
				<?php
				switch ( $status ) {
					case 'draft':
						echo 'Starts assigning visitors and recording events as soon as you click <strong>Start experiment</strong>.';
						break;
					case 'active':
						echo 'Visitors are being assigned variants right now. Pause to stop new assignments without losing data, or conclude to freeze the result.';
						break;
					case 'paused':
						echo 'No new assignments are being made. Existing assigned visitors still see their cookie-stuck variant.';
						break;
				}
				?>
			</p>
		</div>
		<?php
	}

	private static function transition_button( $id, $to, $label, $class = 'button', $confirm = '' ) {
		?>
		<form method="post" style="margin:0;" <?php if ( $confirm ) echo 'onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"'; ?>>
			<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
			<input type="hidden" name="pbd_exp_action" value="transition_status">
			<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="new_status" value="<?php echo esc_attr( $to ); ?>">
			<button type="submit" class="button <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_variants_table( $variants, $is_locked, $target_path, $page_templates ) {
		$total_weight = 0;
		foreach ( $variants as $v ) {
			$total_weight += max( 0, (int) $v['weight'] );
		}
		$target_display = $target_path ? $target_path : '/';
		?>
		<div class="pbd-exp-split-bar" id="pbd-exp-split-bar" aria-label="Traffic split between variants">
			<?php foreach ( $variants as $i => $v ) :
				$share = $total_weight > 0 ? round( ( max( 0, (int) $v['weight'] ) / $total_weight ) * 100 ) : 0;
			?>
				<div class="pbd-exp-split-bar__seg" data-row-index="<?php echo (int) $i; ?>" style="flex:<?php echo (int) max( 0, (int) $v['weight'] ); ?> 1 0;">
					<span class="pbd-exp-split-bar__label"><?php echo esc_html( $v['label'] ); ?> <span class="pct"><?php echo (int) $share; ?>%</span></span>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="pbd-exp-split-bar__warning" id="pbd-exp-split-warning" hidden>Variant weights total <span data-total>0</span>%, not 100%.</p>

		<table class="pbd-exp-table pbd-exp-table-variants pbd-exp-hide-keys" id="pbd-exp-variants" data-target-path="<?php echo esc_attr( $target_display ); ?>">
			<thead>
				<tr>
					<th class="col-handle" aria-label="Reorder"></th>
					<th class="col-key pbd-exp-keys-col">ID</th>
					<th class="col-label">Label</th>
					<th class="col-weight">Weight / share</th>
					<th class="col-type">Type</th>
					<th class="col-dest">Destination</th>
					<th class="col-remove" aria-label="Remove"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $variants as $i => $v ) :
					$share = $total_weight > 0 ? round( ( max( 0, (int) $v['weight'] ) / $total_weight ) * 100 ) : 0;
					$variant_label = $v['label'] ? $v['label'] : 'variant ' . ( $i + 1 );
					$is_control = ( 0 === $i );
					$has_override = $is_control && ( 'template' !== $v['variant_type'] || ! empty( $v['template_path'] ) || ! empty( $v['redirect_url'] ) );
				?>
					<tr class="pbd-exp-variant-row <?php echo $is_control ? 'pbd-exp-variant-row--control' : ''; ?>" data-existing-id="<?php echo esc_attr( $v['id'] ); ?>" data-is-control="<?php echo $is_control ? '1' : '0'; ?>">
						<td class="pbd-exp-drag" title="<?php echo $is_control ? 'Control is always first' : 'Drag to reorder'; ?>" aria-label="<?php echo $is_control ? 'Control row' : 'Drag to reorder'; ?>">
							<?php if ( ! $is_control ) : ?>
								<span class="pbd-exp-drag__grip" aria-hidden="true"></span>
							<?php else : ?>
								<span class="pbd-exp-control-pin" aria-hidden="true">&#9679;</span>
							<?php endif; ?>
						</td>
						<td class="pbd-exp-keys-col">
							<input type="hidden" name="variants[<?php echo $i; ?>][id]" value="<?php echo esc_attr( $v['id'] ); ?>">
							<input type="text" name="variants[<?php echo $i; ?>][variant_key]" value="<?php echo esc_attr( $v['variant_key'] ); ?>" placeholder="<?php echo $is_control ? 'control' : 'variant_b'; ?>" class="pbd-exp-variant-key" <?php disabled( $is_locked ); ?>>
						</td>
						<td>
							<input type="text" name="variants[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $v['label'] ); ?>" placeholder="<?php echo $is_control ? 'Control' : 'Variant B'; ?>" class="pbd-exp-variant-label" <?php disabled( $is_locked ); ?>>
						</td>
						<td>
							<input type="number" name="variants[<?php echo $i; ?>][weight]" min="0" value="<?php echo esc_attr( $v['weight'] ); ?>" class="pbd-exp-weight" <?php disabled( $is_locked ); ?>>
							<span class="pbd-exp-variant-share"><?php echo (int) $share; ?>%</span>
						</td>
						<?php if ( $is_control ) : ?>
							<td colspan="2" class="pbd-exp-control-dest <?php echo $has_override ? 'is-overridden' : ''; ?>">
								<div class="pbd-exp-control-dest__default" <?php if ( $has_override ) echo 'hidden'; ?>>
									<span class="pbd-exp-control-dest__pill">Original page at <code class="pbd-exp-control-target"><?php echo esc_html( $target_display ); ?></code></span>
									<?php if ( ! $is_locked ) : ?>
										<button type="button" class="pbd-exp-link-btn pbd-exp-control-override-toggle" data-action="reveal">Override the original page&hellip;</button>
									<?php endif; ?>
								</div>
								<div class="pbd-exp-control-dest__override" <?php if ( ! $has_override ) echo 'hidden'; ?>>
									<div class="pbd-exp-segmented pbd-exp-variant-type-seg" role="radiogroup" aria-label="Destination type">
										<label>
											<input type="radio" name="variants[<?php echo $i; ?>][variant_type]" value="template" class="pbd-exp-variant-type" <?php checked( $v['variant_type'], 'template' ); ?> <?php disabled( $is_locked ); ?>>
											<span>Template</span>
										</label>
										<label>
											<input type="radio" name="variants[<?php echo $i; ?>][variant_type]" value="redirect" class="pbd-exp-variant-type" <?php checked( $v['variant_type'], 'redirect' ); ?> <?php disabled( $is_locked ); ?>>
											<span>Redirect</span>
										</label>
									</div>
									<div class="pbd-exp-dest-cell">
										<?php self::render_template_destination( $i, $v, $is_locked, $page_templates ); ?>
										<input type="text" name="variants[<?php echo $i; ?>][redirect_url]" value="<?php echo esc_attr( $v['redirect_url'] ); ?>" placeholder="/free-classes-v2/" class="pbd-exp-redirect-url" <?php if ( 'redirect' !== $v['variant_type'] ) echo 'hidden'; ?> <?php disabled( $is_locked ); ?>>
									</div>
									<?php if ( ! $is_locked ) : ?>
										<button type="button" class="pbd-exp-link-btn pbd-exp-control-override-toggle" data-action="restore">Use the original page</button>
									<?php endif; ?>
								</div>
								<input type="hidden" name="variants[<?php echo $i; ?>][_has_override]" class="pbd-exp-control-override-flag" value="<?php echo $has_override ? '1' : '0'; ?>">
							</td>
						<?php else : ?>
							<td>
								<div class="pbd-exp-segmented pbd-exp-variant-type-seg" role="radiogroup" aria-label="Destination type">
									<label>
										<input type="radio" name="variants[<?php echo $i; ?>][variant_type]" value="template" class="pbd-exp-variant-type" <?php checked( $v['variant_type'], 'template' ); ?> <?php disabled( $is_locked ); ?>>
										<span>Template</span>
									</label>
									<label>
										<input type="radio" name="variants[<?php echo $i; ?>][variant_type]" value="redirect" class="pbd-exp-variant-type" <?php checked( $v['variant_type'], 'redirect' ); ?> <?php disabled( $is_locked ); ?>>
										<span>Redirect</span>
									</label>
								</div>
							</td>
							<td class="pbd-exp-dest-cell">
								<?php self::render_template_destination( $i, $v, $is_locked, $page_templates ); ?>
								<input type="text" name="variants[<?php echo $i; ?>][redirect_url]" value="<?php echo esc_attr( $v['redirect_url'] ); ?>" placeholder="/free-classes-v2/" class="pbd-exp-redirect-url" <?php if ( 'redirect' !== $v['variant_type'] ) echo 'hidden'; ?> <?php disabled( $is_locked ); ?>>
							</td>
						<?php endif; ?>
						<td class="col-remove">
							<?php if ( ! $is_locked && ! $is_control ) : ?>
								<button type="button" class="pbd-exp-remove-row" title="Remove variant" aria-label="Remove <?php echo esc_attr( $variant_label ); ?>">
									<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<?php if ( ! $is_locked ) : ?>
				<tfoot>
					<tr>
						<td colspan="7"><button type="button" class="button" id="pbd-exp-add-variant">+ Add variant</button></td>
					</tr>
				</tfoot>
			<?php endif; ?>
		</table>
		<p class="pbd-exp-field__hint"><strong>Template</strong> swaps the page template file (child theme first, then parent). <strong>Redirect</strong> sends the visitor to another same-site URL. Off-site redirects are rejected on save.</p>
		<script type="text/template" id="pbd-exp-template-options">
			<option value="">Default template</option>
			<?php foreach ( $page_templates as $filename => $name ) : ?>
				<option value="<?php echo esc_attr( $filename ); ?>"><?php echo esc_html( $name ); ?> (<?php echo esc_html( $filename ); ?>)</option>
			<?php endforeach; ?>
			<option value="__custom__">Other file&hellip;</option>
		</script>
		<?php
	}

	private static function render_template_destination( $i, $v, $is_locked, $page_templates ) {
		$current_path = isset( $v['template_path'] ) ? (string) $v['template_path'] : '';
		$is_known     = '' !== $current_path && isset( $page_templates[ $current_path ] );
		$use_custom   = '' !== $current_path && ! $is_known;
		$visible      = 'redirect' !== $v['variant_type'];
		?>
		<span class="pbd-exp-template-picker" <?php if ( ! $visible ) echo 'hidden'; ?>>
			<select class="pbd-exp-template-select" data-row-index="<?php echo (int) $i; ?>" <?php disabled( $is_locked ); ?>>
				<option value="" <?php selected( '' === $current_path ); ?>>Default template</option>
				<?php foreach ( $page_templates as $filename => $name ) : ?>
					<option value="<?php echo esc_attr( $filename ); ?>" <?php selected( $filename === $current_path ); ?>>
						<?php echo esc_html( $name ); ?> (<?php echo esc_html( $filename ); ?>)
					</option>
				<?php endforeach; ?>
				<option value="__custom__" <?php selected( $use_custom ); ?>>Other file&hellip;</option>
			</select>
			<input type="text" class="pbd-exp-template-path-custom" placeholder="path/to/template.php" value="<?php echo $use_custom ? esc_attr( $current_path ) : ''; ?>" <?php if ( ! $use_custom ) echo 'hidden'; ?> <?php disabled( $is_locked ); ?>>
			<input type="hidden" name="variants[<?php echo (int) $i; ?>][template_path]" class="pbd-exp-template-path" value="<?php echo esc_attr( $current_path ); ?>">
		</span>
		<?php
	}

	private static function render_metrics_table( $metrics, $is_locked, $experiment ) {
		$exp_key = $experiment['experiment_key'] ? $experiment['experiment_key'] : 'your_experiment_id';
		?>
		<table class="pbd-exp-table pbd-exp-table-metrics pbd-exp-hide-keys" id="pbd-exp-metrics" data-experiment-key="<?php echo esc_attr( $exp_key ); ?>">
			<thead>
				<tr>
					<th class="pbd-exp-keys-col">ID</th>
					<th>Name</th>
					<th>What triggers it?</th>
					<th class="pbd-exp-keys-col">Event name</th>
					<th class="col-active">Active</th>
					<th class="col-remove" aria-label="Remove"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $metrics as $i => $m ) :
					$metric_label = ! empty( $m['name'] ) ? $m['name'] : ( ! empty( $m['metric_key'] ) ? $m['metric_key'] : 'metric ' . ( $i + 1 ) );
				?>
					<tr class="pbd-exp-metric-row">
						<td class="pbd-exp-keys-col">
							<input type="hidden" name="metrics[<?php echo $i; ?>][id]" value="<?php echo esc_attr( isset( $m['id'] ) ? $m['id'] : 0 ); ?>">
							<input type="text" name="metrics[<?php echo $i; ?>][metric_key]" value="<?php echo esc_attr( $m['metric_key'] ); ?>" placeholder="opt_in" class="pbd-exp-metric-key" <?php disabled( $is_locked ); ?>>
						</td>
						<td><input type="text" name="metrics[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $m['name'] ); ?>" placeholder="Opt-in" class="pbd-exp-metric-name" <?php disabled( $is_locked ); ?>></td>
						<?php $trigger_type = ! empty( $m['trigger_type'] ) ? $m['trigger_type'] : 'page'; ?>
						<td>
							<select name="metrics[<?php echo $i; ?>][trigger_type]" class="pbd-exp-metric-trigger" <?php disabled( $is_locked ); ?>>
								<option value="page" <?php selected( $trigger_type, 'page' ); ?>>Visiting a specific page</option>
								<option value="form" <?php selected( $trigger_type, 'form' ); ?>>Submitting a form</option>
								<option value="js" <?php selected( $trigger_type, 'js' ); ?>>Custom JavaScript</option>
							</select>
							<span class="pbd-exp-metric-form-config" <?php echo 'form' === $trigger_type ? '' : 'hidden'; ?>>
								<input type="text" name="metrics[<?php echo $i; ?>][selector]" value="<?php echo esc_attr( isset( $m['selector'] ) ? $m['selector'] : '' ); ?>" placeholder="#inf_form, .infusion-form, form.optin" class="pbd-exp-metric-selector" <?php disabled( $is_locked ); ?>>
								<?php $form_type = isset( $m['form_type'] ) ? $m['form_type'] : ''; ?>
								<select name="metrics[<?php echo $i; ?>][form_type]" class="pbd-exp-metric-formtype" <?php disabled( $is_locked ); ?>>
									<option value="native" <?php selected( $form_type, 'native' ); ?>>Standard form (incl. Infusionsoft / Keap)</option>
									<option value="cf7" <?php selected( $form_type, 'cf7' ); ?>>Contact Form 7</option>
								</select>
							</span>
						</td>
						<td class="pbd-exp-keys-col"><input type="text" name="metrics[<?php echo $i; ?>][event_name]" value="<?php echo esc_attr( $m['event_name'] ); ?>" placeholder="opt_in" class="pbd-exp-metric-event" <?php disabled( $is_locked ); ?>></td>
						<td class="col-active"><input type="checkbox" name="metrics[<?php echo $i; ?>][active]" value="1" <?php checked( ! empty( $m['active'] ) ); ?> <?php disabled( $is_locked ); ?>></td>
						<td class="col-remove">
							<?php if ( ! $is_locked ) : ?>
								<button type="button" class="pbd-exp-remove-row" title="Remove metric" aria-label="Remove <?php echo esc_attr( $metric_label ); ?>">
									<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								</button>
							<?php endif; ?>
						</td>
					</tr>
					<tr class="pbd-exp-metric-snippet-row">
						<td colspan="6">
							<?php self::render_metric_snippets( $m, $exp_key ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<?php if ( ! $is_locked ) : ?>
				<tfoot>
					<tr>
						<td colspan="6"><button type="button" class="button" id="pbd-exp-add-metric">+ Add metric</button></td>
					</tr>
				</tfoot>
			<?php endif; ?>
		</table>
		<p class="pbd-exp-field__hint">First active metric drives the headline rate on the dashboard. Inactive metrics stop counting but their history stays.</p>
		<?php
	}

	private static function render_metric_snippets( $metric, $exp_key ) {
		$event = $metric['event_name'] ? $metric['event_name'] : 'opt_in';
		?>
		<div class="pbd-exp-metric-snippet" data-snippet-for="page">
			<p class="pbd-exp-metric-snippet__lede"><strong>Paste this shortcode into the page that confirms the conversion</strong> (e.g. the thank-you page someone lands on after submitting).</p>
			<p class="pbd-exp-metric-snippet__how">In WordPress: edit the page &rarr; <em>+</em> &rarr; add a <strong>Shortcode</strong> block &rarr; paste &rarr; Save.</p>
			<div class="pbd-exp-snippet">
				<code class="pbd-exp-snippet-code">[pbd_experiment_event event=&quot;<span class="pbd-exp-snippet-event"><?php echo esc_html( $event ); ?></span>&quot; experiment=&quot;<span class="pbd-exp-snippet-exp"><?php echo esc_html( $exp_key ); ?></span>&quot; once=&quot;1&quot;]</code>
				<button type="button" class="copy-btn">Copy</button>
			</div>
		</div>
		<div class="pbd-exp-metric-snippet" data-snippet-for="form">
			<p class="pbd-exp-metric-snippet__lede"><strong>Enter the CSS selector for the form above</strong> (e.g. <code>#inf_form</code>, <code>.infusion-form</code>, <code>form.optin</code>). On a page with several forms, this is how you target the right one. The conversion is recorded the moment that form is submitted, on the test page itself, so it works even when the form posts off-site (Infusionsoft / Keap) and redirects back.</p>
			<p class="pbd-exp-metric-snippet__how">Right-click the form in your browser &rarr; Inspect &rarr; read its <code>id</code> or <code>class</code>. Prefer an <code>#id</code>: it is the most specific.</p>
			<p class="pbd-exp-metric-snippet__how"><em>Advanced (optional):</em> instead of a selector you can hand-add attributes to the form's HTML &mdash; <code>&lt;form data-pbd-exp=&quot;<span class="pbd-exp-snippet-exp"><?php echo esc_html( $exp_key ); ?></span>&quot; data-pbd-event=&quot;<span class="pbd-exp-snippet-event"><?php echo esc_html( $event ); ?></span>&quot;&gt;</code></p>
		</div>
		<div class="pbd-exp-metric-snippet" data-snippet-for="js" hidden>
			<p class="pbd-exp-metric-snippet__lede"><strong>Call this from JavaScript</strong> when the conversion happens (button click, ajax response, custom flow).</p>
			<div class="pbd-exp-snippet">
				<code class="pbd-exp-snippet-code">PBDExperiments.track('<span class="pbd-exp-snippet-event"><?php echo esc_html( $event ); ?></span>', { experiment: '<span class="pbd-exp-snippet-exp"><?php echo esc_html( $exp_key ); ?></span>', once: true });</code>
				<button type="button" class="copy-btn">Copy</button>
			</div>
		</div>
		<?php
	}

	public static function handle_save() {
		$id            = isset( $_POST['experiment_id'] ) ? absint( $_POST['experiment_id'] ) : 0;
		$existing      = $id ? PBD_Exp_Repo::get_experiment( $id ) : null;

		// Concluded experiments are config-locked. Only the winning_variant_id field is editable.
		if ( $existing && 'concluded' === $existing['status'] ) {
			$winner = isset( $_POST['winning_variant_id'] ) && '' !== $_POST['winning_variant_id'] ? absint( $_POST['winning_variant_id'] ) : null;
			PBD_Exp_Repo::update_experiment( $id, array( 'winning_variant_id' => $winner ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $id . '&message=saved' ) );
			exit;
		}

		$name           = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$experiment_key = sanitize_key( wp_unslash( $_POST['experiment_key'] ?? '' ) );
		if ( ! $experiment_key ) {
			$experiment_key = self::slugify( $name );
		}
		$target_path    = untrailingslashit( sanitize_text_field( wp_unslash( $_POST['target_path'] ?? '/' ) ) );
		$cookie_days    = max( 1, absint( $_POST['cookie_days'] ?? 90 ) );
		$include_li     = ! empty( $_POST['include_logged_in'] ) ? 1 : 0;
		$notes          = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $experiment_key || ! $name ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&message=missing' ) );
			exit;
		}

		$payload = array(
			'experiment_key'    => $experiment_key,
			'name'              => $name,
			'target_path'       => $target_path ? $target_path : '/',
			'cookie_days'       => $cookie_days,
			'include_logged_in' => $include_li,
			'notes'             => $notes,
		);

		if ( $id ) {
			PBD_Exp_Repo::update_experiment( $id, $payload );
		} else {
			$payload['status'] = 'draft';
			$id = PBD_Exp_Repo::insert_experiment( $payload );
		}

		self::save_variants( $id );
		self::save_metrics( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $id . '&message=saved' ) );
		exit;
	}

	private static function save_variants( $experiment_id ) {
		$rows = isset( $_POST['variants'] ) && is_array( $_POST['variants'] ) ? $_POST['variants'] : array();
		$kept = array();
		$order = 0;

		foreach ( $rows as $idx => $row ) {
			$is_control = ( 0 === $order );

			$label = sanitize_text_field( wp_unslash( $row['label'] ?? '' ) );

			$key = sanitize_key( wp_unslash( $row['variant_key'] ?? '' ) );
			if ( ! $key ) {
				$key = $is_control ? 'control' : self::slugify( $label );
			}
			if ( ! $key ) {
				continue;
			}

			$has_override = $is_control ? ! empty( $row['_has_override'] ) : true;

			if ( ! $has_override ) {
				// Control with no override: lock to original page (template, empty path).
				$variant_type  = 'template';
				$template_path = '';
				$redirect_url  = '';
			} else {
				$variant_type = in_array( $row['variant_type'] ?? 'template', array( 'template', 'redirect' ), true ) ? $row['variant_type'] : 'template';
				$template_path = isset( $row['template_path'] ) ? sanitize_text_field( wp_unslash( $row['template_path'] ) ) : '';
				$redirect_url  = isset( $row['redirect_url'] ) ? esc_url_raw( wp_unslash( $row['redirect_url'] ) ) : '';
				if ( 'redirect' === $variant_type && $redirect_url && ! self::is_same_site( $redirect_url ) ) {
					// Refuse off-site redirects silently for now; UI validates client-side too.
					$redirect_url = '';
				}
			}

			$id = PBD_Exp_Repo::upsert_variant(
				$experiment_id,
				array(
					'variant_key'   => $key,
					'label'         => $label ? $label : $key,
					'weight'        => isset( $row['weight'] ) ? absint( $row['weight'] ) : 0,
					'variant_type'  => $variant_type,
					'template_path' => $template_path,
					'redirect_url'  => $redirect_url,
					'sort_order'    => $order++,
				)
			);
			$kept[] = $id;
		}

		PBD_Exp_Repo::delete_variants_except( $experiment_id, $kept );
	}

	private static function save_metrics( $experiment_id ) {
		$rows = isset( $_POST['metrics'] ) && is_array( $_POST['metrics'] ) ? $_POST['metrics'] : array();
		$kept = array();
		$order = 0;

		foreach ( $rows as $row ) {
			$name  = sanitize_text_field( wp_unslash( $row['name'] ?? '' ) );
			$key   = sanitize_key( wp_unslash( $row['metric_key'] ?? '' ) );
			$event = sanitize_key( wp_unslash( $row['event_name'] ?? '' ) );

			if ( ! $key ) {
				$key = self::slugify( $name );
			}
			if ( ! $event ) {
				$event = $key;
			}
			if ( ! $key || ! $event ) {
				continue;
			}

			$trigger = sanitize_key( wp_unslash( $row['trigger_type'] ?? 'page' ) );
			if ( ! in_array( $trigger, array( 'page', 'form', 'js' ), true ) ) {
				$trigger = 'page';
			}

			$selector = isset( $row['selector'] ) ? self::sanitize_selector( wp_unslash( $row['selector'] ) ) : '';

			$form_type = sanitize_key( wp_unslash( $row['form_type'] ?? '' ) );
			if ( ! in_array( $form_type, array( '', 'native', 'cf7' ), true ) ) {
				$form_type = '';
			}

			// Selector and form platform only make sense for form triggers.
			if ( 'form' !== $trigger ) {
				$selector  = '';
				$form_type = '';
			}

			$id = PBD_Exp_Repo::upsert_metric(
				$experiment_id,
				array(
					'metric_key'   => $key,
					'name'         => $name ? $name : $key,
					'event_name'   => $event,
					'trigger_type' => $trigger,
					'selector'     => $selector,
					'form_type'    => $form_type,
					'active'       => ! empty( $row['active'] ),
					'sort_order'   => $order++,
				)
			);
			$kept[] = $id;
		}

		PBD_Exp_Repo::delete_metrics_except( $experiment_id, $kept );
	}

	public static function handle_delete() {
		$id = isset( $_POST['experiment_id'] ) ? absint( $_POST['experiment_id'] ) : 0;
		if ( ! $id ) {
			return;
		}
		PBD_Exp_Repo::delete_experiment( $id );

		$default = admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG );
		$target  = $default;
		if ( ! empty( $_POST['redirect_to'] ) ) {
			$candidate = wp_unslash( $_POST['redirect_to'] );
			$admin_url = admin_url();
			if ( 0 === strpos( $candidate, $admin_url ) ) {
				$target = $candidate;
			}
		}
		$target = add_query_arg( 'message', 'deleted', $target );

		wp_safe_redirect( $target );
		exit;
	}

	public static function handle_clone() {
		$id = isset( $_POST['experiment_id'] ) ? absint( $_POST['experiment_id'] ) : 0;
		$source = $id ? PBD_Exp_Repo::get_experiment( $id ) : null;
		if ( ! $source ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG ) );
			exit;
		}

		$new_key = self::next_clone_key( $source['experiment_key'] );
		$new_name = $source['name'] . ' (clone)';

		$new_id = PBD_Exp_Repo::insert_experiment( array(
			'experiment_key'    => $new_key,
			'name'              => $new_name,
			'status'            => 'draft',
			'target_path'       => $source['target_path'],
			'cookie_days'       => (int) $source['cookie_days'],
			'include_logged_in' => (int) $source['include_logged_in'],
			'notes'             => isset( $source['notes'] ) ? $source['notes'] : '',
		) );

		// Copy variants
		$src_variants = PBD_Exp_Repo::get_variants( (int) $source['id'] );
		$order = 0;
		foreach ( $src_variants as $v ) {
			PBD_Exp_Repo::upsert_variant( $new_id, array(
				'variant_key'   => $v['variant_key'],
				'label'         => $v['label'],
				'weight'        => (int) $v['weight'],
				'variant_type'  => $v['variant_type'],
				'template_path' => $v['template_path'],
				'redirect_url'  => $v['redirect_url'],
				'sort_order'    => $order++,
			) );
		}

		// Copy metrics
		$src_metrics = PBD_Exp_Repo::get_metrics( (int) $source['id'] );
		$morder = 0;
		foreach ( $src_metrics as $m ) {
			PBD_Exp_Repo::upsert_metric( $new_id, array(
				'metric_key'   => $m['metric_key'],
				'name'         => $m['name'],
				'event_name'   => $m['event_name'],
				'trigger_type' => isset( $m['trigger_type'] ) ? $m['trigger_type'] : 'page',
				'selector'     => isset( $m['selector'] ) ? $m['selector'] : '',
				'form_type'    => isset( $m['form_type'] ) ? $m['form_type'] : '',
				'active'       => ! empty( $m['active'] ),
				'sort_order'   => $morder++,
			) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $new_id . '&message=cloned' ) );
		exit;
	}

	private static function next_clone_key( $base_key ) {
		// Strip trailing -N suffix and bump.
		$base = preg_replace( '/-(\d+)$/', '', $base_key );
		$n = 2;
		while ( $n < 1000 ) {
			$candidate = $base . '-' . $n;
			if ( ! PBD_Exp_Repo::get_experiment_by_key( $candidate ) ) {
				return $candidate;
			}
			$n++;
		}
		return $base . '-' . wp_generate_password( 4, false, false );
	}

	public static function handle_transition() {
		$id  = isset( $_POST['experiment_id'] ) ? absint( $_POST['experiment_id'] ) : 0;
		$new = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';
		$experiment = $id ? PBD_Exp_Repo::get_experiment( $id ) : null;

		if ( ! $experiment ) {
			return;
		}

		$allowed = self::ALLOWED_TRANSITIONS[ $experiment['status'] ] ?? array();
		if ( ! in_array( $new, $allowed, true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $id . '&message=invalid_transition' ) );
			exit;
		}

		$now = current_time( 'mysql' );
		$payload = array( 'status' => $new );

		if ( 'active' === $new && empty( $experiment['started_at'] ) ) {
			$payload['started_at'] = $now;
		}

		if ( 'concluded' === $new ) {
			$payload['concluded_at'] = $now;
			PBD_Exp_Repo::save_snapshot( $id, PBD_Exp_Admin_Dashboard::build_snapshot( $experiment ) );
		}

		PBD_Exp_Repo::update_experiment( $id, $payload );

		$msg = 'saved';
		if ( 'concluded' === $new ) {
			$msg = 'concluded';
		} elseif ( 'active' === $new ) {
			$msg = 'paused' === $experiment['status'] ? 'resumed' : 'started';
		} elseif ( 'paused' === $new ) {
			$msg = 'paused';
		}

		// Concluding kicks the user to the archive; everything else stays on the edit screen.
		if ( 'concluded' === $new ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&message=concluded' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $id . '&message=' . $msg ) );
		}
		exit;
	}

	private static function slugify( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		$value = trim( $value, '_' );
		return substr( $value, 0, 64 );
	}

	/**
	 * Sanitize a user-entered CSS selector before it is stored and later emitted
	 * into the inline front-end config (JSON) and used by element.matches().
	 * Strips characters that have no place in a selector and that could break out
	 * of the inline <script> (angle brackets, backticks, control chars), keeps the
	 * realistic selector vocabulary, and caps to the column width.
	 */
	private static function sanitize_selector( $raw ) {
		$value = trim( (string) $raw );
		// Drop control characters and the inline-script breakout vector.
		$value = preg_replace( '/[\x00-\x1f<>`]+/', '', $value );
		// Keep only characters used by real selectors: identifiers, combinators,
		// attribute/pseudo syntax, and whitespace.
		$value = preg_replace( '/[^a-zA-Z0-9 #\.\-_\[\]="\':\(\),\*>\+~@]/', '', $value );
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, 255 );
		} else {
			$value = substr( $value, 0, 255 );
		}
		return $value;
	}

	private static function is_same_site( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$site_host = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';

		if ( '' !== $url && '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			return true;
		}

		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return false;
		}

		return strtolower( $parts['host'] ) === $site_host;
	}
}
