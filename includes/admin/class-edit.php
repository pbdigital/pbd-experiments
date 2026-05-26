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
		'paused'    => array( 'active', 'concluded' ),
		'concluded' => array(),
	);

	public static function render( $id = 0 ) {
		$experiment = $id ? PBD_Exp_Repo::get_experiment( $id ) : null;
		$variants   = $experiment ? PBD_Exp_Repo::get_variants( $experiment['id'] ) : array();
		$metrics    = $experiment ? PBD_Exp_Repo::get_metrics( $experiment['id'] ) : array();

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
				array( 'id' => 0, 'variant_key' => 'variant', 'label' => 'Variant', 'weight' => 50, 'variant_type' => 'template', 'template_path' => '', 'redirect_url' => '', 'sort_order' => 1 ),
			);
		}

		if ( empty( $metrics ) ) {
			$metrics = array(
				array( 'id' => 0, 'metric_key' => 'opt_in', 'name' => 'Opt-in', 'event_name' => 'opt_in', 'active' => 1, 'sort_order' => 0 ),
			);
		}

		$is_locked = 'concluded' === $experiment['status'];
		$allowed_transitions = self::ALLOWED_TRANSITIONS[ $experiment['status'] ] ?? array();
		?>
		<div class="wrap pbd-exp-wrap">
			<h1>
				<?php echo $experiment['id'] ? 'Edit Experiment' : 'Add Experiment'; ?>
				<?php if ( $experiment['id'] ) : ?>
					<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=dashboard&id=' . $experiment['id'] ) ); ?>">View dashboard</a>
				<?php endif; ?>
			</h1>

			<?php if ( $is_locked ) : ?>
				<div class="notice notice-warning"><p><strong>This experiment is concluded.</strong> Configuration is locked. To re-run, clone it as a new experiment.</p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
				<input type="hidden" name="pbd_exp_action" value="save_experiment">
				<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $experiment['id'] ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="experiment_key">Key</label></th>
						<td>
							<input class="regular-text" id="experiment_key" name="experiment_key" value="<?php echo esc_attr( $experiment['experiment_key'] ); ?>" <?php wp_readonly( (bool) $experiment['id'] ); ?> <?php disabled( $is_locked ); ?>>
							<p class="description">Lowercase identifier. Used in event tracking, dataLayer, and Clarity tags. Cannot be changed once saved.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="name">Name</label></th>
						<td><input class="regular-text" id="name" name="name" value="<?php echo esc_attr( $experiment['name'] ); ?>" <?php disabled( $is_locked ); ?>></td>
					</tr>
					<tr>
						<th scope="row"><label for="target_path">Target path</label></th>
						<td>
							<input class="regular-text" id="target_path" name="target_path" value="<?php echo esc_attr( $experiment['target_path'] ); ?>" <?php disabled( $is_locked ); ?>>
							<p class="description">Site-root-relative path that triggers this experiment. Example: <code>/free-classes</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cookie_days">Cookie days</label></th>
						<td><input class="small-text" id="cookie_days" name="cookie_days" type="number" min="1" value="<?php echo esc_attr( $experiment['cookie_days'] ); ?>" <?php disabled( $is_locked ); ?>></td>
					</tr>
					<tr>
						<th scope="row">Include logged-in users</th>
						<td>
							<label>
								<input type="checkbox" name="include_logged_in" value="1" <?php checked( ! empty( $experiment['include_logged_in'] ) ); ?> <?php disabled( $is_locked ); ?>>
								Assign and track logged-in WordPress users for this experiment
							</label>
							<p class="description">Default off. Internal browsing shouldn't pollute results.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="notes">Notes</label></th>
						<td>
							<textarea id="notes" name="notes" rows="3" class="large-text" <?php disabled( $is_locked ); ?>><?php echo esc_textarea( isset( $experiment['notes'] ) ? $experiment['notes'] : '' ); ?></textarea>
							<p class="description">Optional. What you're testing, hypotheses, what you learned.</p>
						</td>
					</tr>
				</table>

				<h2>Variants</h2>
				<?php self::render_variants_table( $variants, $is_locked ); ?>

				<h2>Metrics</h2>
				<?php self::render_metrics_table( $metrics, $is_locked ); ?>

				<?php if ( $experiment['id'] && 'concluded' === $experiment['status'] ) : ?>
					<h2>Winning variant</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="winning_variant_id">Declared winner</label></th>
							<td>
								<select id="winning_variant_id" name="winning_variant_id">
									<option value="">(none)</option>
									<?php foreach ( $variants as $v ) : ?>
										<option value="<?php echo esc_attr( $v['id'] ); ?>" <?php selected( (int) $experiment['winning_variant_id'], (int) $v['id'] ); ?>><?php echo esc_html( $v['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Self-declared. No automatic significance calculation.</p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<?php if ( ! $is_locked ) : ?>
					<?php submit_button( 'Save Experiment' ); ?>
				<?php endif; ?>
			</form>

			<?php if ( $experiment['id'] ) : ?>
				<hr>
				<h2>Status: <span class="pbd-exp-status pbd-exp-status--<?php echo esc_attr( $experiment['status'] ); ?>"><?php echo esc_html( ucfirst( $experiment['status'] ) ); ?></span></h2>

				<?php if ( ! empty( $allowed_transitions ) ) : ?>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
						<input type="hidden" name="pbd_exp_action" value="transition_status">
						<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $experiment['id'] ); ?>">
						<label for="new_status">Change to:</label>
						<select name="new_status" id="new_status">
							<?php foreach ( $allowed_transitions as $next ) : ?>
								<option value="<?php echo esc_attr( $next ); ?>"><?php echo esc_html( ucfirst( $next ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button">Apply transition</button>
						<span class="description" style="margin-left:8px;">Concluding freezes the result snapshot and moves the experiment to Past Experiments.</span>
					</form>
				<?php endif; ?>

				<?php if ( 'draft' === $experiment['status'] || 'concluded' === $experiment['status'] ) : ?>
					<form method="post" style="display:inline;margin-left:24px;" onsubmit="return confirm('Permanently delete this experiment and all its assignments and events?');">
						<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
						<input type="hidden" name="pbd_exp_action" value="delete_experiment">
						<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $experiment['id'] ); ?>">
						<button type="submit" class="button button-link-delete">Delete experiment</button>
					</form>
				<?php endif; ?>

				<p class="description" style="margin-top:8px;">
					Allowed transitions from current state: <?php echo $allowed_transitions ? esc_html( implode( ', ', $allowed_transitions ) ) : 'none (terminal)'; ?>.
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_variants_table( $variants, $is_locked ) {
		?>
		<table class="widefat pbd-exp-table-variants" id="pbd-exp-variants">
			<thead>
				<tr>
					<th style="width:24px;"></th>
					<th>Key</th>
					<th>Label</th>
					<th style="width:80px;">Weight</th>
					<th style="width:120px;">Type</th>
					<th>Destination</th>
					<th style="width:32px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $variants as $i => $v ) : ?>
					<tr class="pbd-exp-variant-row" data-existing-id="<?php echo esc_attr( $v['id'] ); ?>">
						<td class="pbd-exp-drag" title="Drag to reorder">&#x2630;</td>
						<td>
							<input type="hidden" name="variants[<?php echo $i; ?>][id]" value="<?php echo esc_attr( $v['id'] ); ?>">
							<input type="text" name="variants[<?php echo $i; ?>][variant_key]" value="<?php echo esc_attr( $v['variant_key'] ); ?>" placeholder="control" <?php disabled( $is_locked ); ?>>
						</td>
						<td><input type="text" name="variants[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $v['label'] ); ?>" placeholder="Control" <?php disabled( $is_locked ); ?>></td>
						<td><input type="number" name="variants[<?php echo $i; ?>][weight]" min="0" value="<?php echo esc_attr( $v['weight'] ); ?>" <?php disabled( $is_locked ); ?>></td>
						<td>
							<select name="variants[<?php echo $i; ?>][variant_type]" class="pbd-exp-variant-type" <?php disabled( $is_locked ); ?>>
								<option value="template" <?php selected( $v['variant_type'], 'template' ); ?>>Template</option>
								<option value="redirect" <?php selected( $v['variant_type'], 'redirect' ); ?>>Redirect</option>
							</select>
						</td>
						<td>
							<input type="text" name="variants[<?php echo $i; ?>][template_path]" value="<?php echo esc_attr( $v['template_path'] ); ?>" placeholder="page-variant.php" class="pbd-exp-template-path" style="width:100%;<?php echo 'redirect' === $v['variant_type'] ? 'display:none;' : ''; ?>" <?php disabled( $is_locked ); ?>>
							<input type="text" name="variants[<?php echo $i; ?>][redirect_url]" value="<?php echo esc_attr( $v['redirect_url'] ); ?>" placeholder="/free-classes-v2/" class="pbd-exp-redirect-url" style="width:100%;<?php echo 'redirect' === $v['variant_type'] ? '' : 'display:none;'; ?>" <?php disabled( $is_locked ); ?>>
						</td>
						<td>
							<?php if ( ! $is_locked ) : ?>
								<button type="button" class="button-link pbd-exp-remove-row" title="Remove variant">&times;</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $is_locked ) : ?>
			<p><button type="button" class="button" id="pbd-exp-add-variant">+ Add variant</button></p>
		<?php endif; ?>
		<p class="description">Control should be first. Weights are relative. Template paths resolve from your child theme first, then parent theme. Redirect URLs must be same-site (validated server-side).</p>
		<?php
	}

	private static function render_metrics_table( $metrics, $is_locked ) {
		?>
		<table class="widefat pbd-exp-table-metrics" id="pbd-exp-metrics">
			<thead>
				<tr>
					<th>Key</th>
					<th>Name</th>
					<th>Event name (fired from page)</th>
					<th style="width:80px;">Active</th>
					<th style="width:32px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $metrics as $i => $m ) : ?>
					<tr class="pbd-exp-metric-row">
						<td>
							<input type="hidden" name="metrics[<?php echo $i; ?>][id]" value="<?php echo esc_attr( isset( $m['id'] ) ? $m['id'] : 0 ); ?>">
							<input type="text" name="metrics[<?php echo $i; ?>][metric_key]" value="<?php echo esc_attr( $m['metric_key'] ); ?>" placeholder="opt_in" <?php disabled( $is_locked ); ?>>
						</td>
						<td><input type="text" name="metrics[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $m['name'] ); ?>" placeholder="Opt-in" <?php disabled( $is_locked ); ?>></td>
						<td><input type="text" name="metrics[<?php echo $i; ?>][event_name]" value="<?php echo esc_attr( $m['event_name'] ); ?>" placeholder="opt_in" <?php disabled( $is_locked ); ?>></td>
						<td><input type="checkbox" name="metrics[<?php echo $i; ?>][active]" value="1" <?php checked( ! empty( $m['active'] ) ); ?> <?php disabled( $is_locked ); ?>></td>
						<td>
							<?php if ( ! $is_locked ) : ?>
								<button type="button" class="button-link pbd-exp-remove-row" title="Remove metric">&times;</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $is_locked ) : ?>
			<p><button type="button" class="button" id="pbd-exp-add-metric">+ Add metric</button></p>
		<?php endif; ?>
		<p class="description">Each metric is an event name fired from the page (via the JS API, shortcode, or form data attribute). The first active metric drives the headline conversion rate; the dashboard shows all active metrics.</p>
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

		$experiment_key = sanitize_key( wp_unslash( $_POST['experiment_key'] ?? '' ) );
		$name           = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
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

		foreach ( $rows as $row ) {
			$key = sanitize_key( wp_unslash( $row['variant_key'] ?? '' ) );
			if ( ! $key ) {
				continue;
			}

			$variant_type = in_array( $row['variant_type'] ?? 'template', array( 'template', 'redirect' ), true ) ? $row['variant_type'] : 'template';

			$redirect_url = isset( $row['redirect_url'] ) ? esc_url_raw( wp_unslash( $row['redirect_url'] ) ) : '';
			if ( 'redirect' === $variant_type && $redirect_url && ! self::is_same_site( $redirect_url ) ) {
				// Refuse off-site redirects silently for now; UI validates client-side too.
				$redirect_url = '';
			}

			$id = PBD_Exp_Repo::upsert_variant(
				$experiment_id,
				array(
					'variant_key'   => $key,
					'label'         => sanitize_text_field( wp_unslash( $row['label'] ?? $key ) ),
					'weight'        => isset( $row['weight'] ) ? absint( $row['weight'] ) : 0,
					'variant_type'  => $variant_type,
					'template_path' => isset( $row['template_path'] ) ? sanitize_text_field( wp_unslash( $row['template_path'] ) ) : '',
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
			$key   = sanitize_key( wp_unslash( $row['metric_key'] ?? '' ) );
			$event = sanitize_key( wp_unslash( $row['event_name'] ?? '' ) );
			if ( ! $key || ! $event ) {
				continue;
			}
			$id = PBD_Exp_Repo::upsert_metric(
				$experiment_id,
				array(
					'metric_key' => $key,
					'name'       => sanitize_text_field( wp_unslash( $row['name'] ?? $key ) ),
					'event_name' => $event,
					'active'     => ! empty( $row['active'] ),
					'sort_order' => $order++,
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
		wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&message=deleted' ) );
		exit;
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

		$msg = 'concluded' === $new ? 'concluded' : 'saved';
		wp_safe_redirect( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $id . '&message=' . $msg ) );
		exit;
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
