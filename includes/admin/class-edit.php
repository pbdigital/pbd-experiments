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
				array( 'id' => 0, 'variant_key' => 'variant', 'label' => 'Variant', 'weight' => 50, 'variant_type' => 'template', 'template_path' => '', 'redirect_url' => '', 'sort_order' => 1 ),
			);
		}

		if ( empty( $metrics ) ) {
			$metrics = array(
				array( 'id' => 0, 'metric_key' => 'opt_in', 'name' => 'Opt-in', 'event_name' => 'opt_in', 'active' => 1, 'sort_order' => 0 ),
			);
		}

		$is_locked = 'concluded' === $experiment['status'];

		$site_origin = untrailingslashit( home_url( '/' ) );
		$pages       = self::list_target_pages();

		$cancel_url = admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG );
		?>
		<div class="wrap pbd-exp-wrap pbd-exp-edit-screen">
			<h1 class="wp-heading-inline">
				<?php echo $is_new ? 'Add Experiment' : 'Edit experiment: ' . esc_html( $experiment['name'] ); ?>
			</h1>
			<?php if ( $experiment['id'] ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=dashboard&id=' . $experiment['id'] ) ); ?>">View dashboard</a>
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
			);
			if ( isset( $messages[ $flash ] ) ) {
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $flash ][0] ), esc_html( $messages[ $flash ][1] ) );
			}
			?>

			<?php if ( $is_locked ) : ?>
				<div class="notice notice-warning"><p><strong>This experiment is concluded.</strong> Configuration is locked. To re-run, clone it as a new experiment.</p></div>
			<?php endif; ?>

			<?php if ( $experiment['id'] ) : ?>
				<?php self::render_status_bar( $experiment ); ?>
			<?php elseif ( ! $is_locked ) : ?>
				<p class="pbd-exp-status-placeholder">Status appears here after you save.</p>
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
									Key: <code id="pbd-exp-key-preview"></code>
									<button type="button" class="pbd-exp-link-btn" id="pbd-exp-key-edit">edit</button>
								</div>
							<?php endif; ?>
							<div class="pbd-exp-field" id="pbd-exp-key-field" <?php echo $is_new ? 'hidden' : ''; ?> style="margin-top:10px;">
								<label for="experiment_key" class="pbd-exp-field__label">Key</label>
								<input type="text" class="regular-text pbd-exp-input" id="experiment_key" name="experiment_key" value="<?php echo esc_attr( $experiment['experiment_key'] ); ?>" <?php wp_readonly( (bool) $experiment['id'] ); ?> <?php disabled( $is_locked ); ?> placeholder="free_classes_homepage" data-auto-from="#name">
								<p class="pbd-exp-field__hint">Lowercase identifier used in event tracking, dataLayer, and Clarity tags. <?php echo $is_new ? 'Auto-fills from the name.' : 'Cannot be changed once saved.'; ?></p>
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
							<label for="cookie_days" class="pbd-exp-field__label">Cookie days</label>
							<div class="pbd-exp-inline-row">
								<input class="small-text pbd-exp-input" id="cookie_days" name="cookie_days" type="number" min="1" value="<?php echo esc_attr( $experiment['cookie_days'] ); ?>" <?php disabled( $is_locked ); ?>>
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
							<textarea id="notes" name="notes" rows="3" class="large-text pbd-exp-input" <?php disabled( $is_locked ); ?> placeholder="Hypothesis: changing X will increase Y because&hellip;"><?php echo esc_textarea( isset( $experiment['notes'] ) ? $experiment['notes'] : '' ); ?></textarea>
							<p class="pbd-exp-field__hint">Optional. Shown on the dashboard and archive.</p>
						</div>
					</div>
				</div>

				<div class="pbd-exp-card">
					<div class="pbd-exp-card__header">
						<h2>Variants</h2>
						<p class="pbd-exp-card__hint">List Control first. Visitors are split by relative weight.</p>
					</div>
					<div class="pbd-exp-card__body">
						<?php self::render_variants_table( $variants, $is_locked ); ?>
					</div>
				</div>

				<div class="pbd-exp-card">
					<div class="pbd-exp-card__header">
						<h2>Metrics</h2>
						<p class="pbd-exp-card__hint">One event name per metric. First active metric drives the headline rate.</p>
						<button type="button" class="pbd-exp-help-btn" id="pbd-exp-metric-help-btn" aria-label="How to fire metric events" aria-expanded="false">?</button>
					</div>
					<div class="pbd-exp-card__body">
						<?php self::render_metric_snippet_popover( $experiment ); ?>
						<?php self::render_metrics_table( $metrics, $is_locked ); ?>
					</div>
				</div>

				<?php if ( $experiment['id'] && 'concluded' === $experiment['status'] ) : ?>
					<div class="pbd-exp-card">
						<div class="pbd-exp-card__header">
							<h2>Winning variant</h2>
							<p class="pbd-exp-card__hint">Self-declared. No automatic significance calculation.</p>
						</div>
						<div class="pbd-exp-card__body">
							<select id="winning_variant_id" name="winning_variant_id">
								<option value="">(none declared)</option>
								<?php foreach ( $variants as $v ) : ?>
									<option value="<?php echo esc_attr( $v['id'] ); ?>" <?php selected( (int) $experiment['winning_variant_id'], (int) $v['id'] ); ?>><?php echo esc_html( $v['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				<?php endif; ?>

				<div class="pbd-exp-save-bar-spacer" aria-hidden="true"></div>

				<div class="pbd-exp-save-bar" role="region" aria-label="Save actions">
					<a class="pbd-exp-save-bar__cancel" href="<?php echo esc_url( $cancel_url ); ?>">Cancel</a>
					<span class="pbd-exp-save-bar__hint" id="pbd-exp-save-hint"></span>
					<?php if ( ! $is_locked ) : ?>
						<button type="submit" class="button button-primary button-large pbd-exp-save-bar__save">
							<?php echo $is_new ? 'Save and continue' : 'Save changes'; ?>
						</button>
					<?php else : ?>
						<button type="submit" class="button button-primary button-large pbd-exp-save-bar__save">Save winner</button>
					<?php endif; ?>
				</div>
			</form>
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

			<?php if ( 'draft' === $status || 'concluded' === $status ) : ?>
				<form method="post" style="margin:0;" onsubmit="return confirm('Permanently delete this experiment and all its assignments and events? This cannot be undone.');">
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
					case 'concluded':
						echo 'Terminal state. Configuration is locked. Clone as a new experiment to re-run.';
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

	private static function render_metric_snippet_popover( $experiment ) {
		$key = $experiment['experiment_key'] ? $experiment['experiment_key'] : 'your_experiment_key';
		?>
		<div class="pbd-exp-popover" id="pbd-exp-metric-help" hidden>
			<div class="pbd-exp-popover__header">
				<strong>How to fire a metric event</strong>
				<button type="button" class="pbd-exp-popover__close" aria-label="Close">&times;</button>
			</div>
			<p>Use the metric's <em>event name</em> (not the key) from any of these:</p>
			<p class="pbd-exp-popover__label"><strong>JavaScript</strong> &mdash; for buttons and dynamic UI:</p>
			<div class="pbd-exp-snippet">
				<code>PBDExperiments.track('opt_in', { experiment: '<?php echo esc_html( $key ); ?>', once: true });</code>
				<button type="button" class="copy-btn">Copy</button>
			</div>
			<p class="pbd-exp-popover__label"><strong>Form attributes</strong> &mdash; fires on successful submit:</p>
			<div class="pbd-exp-snippet">
				<code>&lt;form data-pbd-exp="<?php echo esc_html( $key ); ?>" data-pbd-event="opt_in"&gt; ... &lt;/form&gt;</code>
				<button type="button" class="copy-btn">Copy</button>
			</div>
			<p class="pbd-exp-popover__label"><strong>Shortcode</strong> &mdash; drop on a thank-you page:</p>
			<div class="pbd-exp-snippet">
				<code>[pbd_experiment_event event="opt_in" experiment="<?php echo esc_html( $key ); ?>" once="1"]</code>
				<button type="button" class="copy-btn">Copy</button>
			</div>
		</div>
		<?php
	}

	private static function render_variants_table( $variants, $is_locked ) {
		$total_weight = 0;
		foreach ( $variants as $v ) {
			$total_weight += max( 0, (int) $v['weight'] );
		}
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

		<table class="pbd-exp-table pbd-exp-table-variants" id="pbd-exp-variants">
			<thead>
				<tr>
					<th class="col-handle" aria-label="Reorder"></th>
					<th class="col-key">Key</th>
					<th class="col-label">Label</th>
					<th class="col-weight">Weight / share</th>
					<th class="col-type">Type</th>
					<th class="col-dest"><span class="js-dest-label">Template file</span></th>
					<th class="col-remove" aria-label="Remove"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $variants as $i => $v ) :
					$share = $total_weight > 0 ? round( ( max( 0, (int) $v['weight'] ) / $total_weight ) * 100 ) : 0;
					$variant_label = $v['label'] ? $v['label'] : 'variant ' . ( $i + 1 );
				?>
					<tr class="pbd-exp-variant-row" data-existing-id="<?php echo esc_attr( $v['id'] ); ?>">
						<td class="pbd-exp-drag" title="Drag to reorder" aria-label="Drag to reorder">
							<span class="pbd-exp-drag__grip" aria-hidden="true"></span>
						</td>
						<td>
							<input type="hidden" name="variants[<?php echo $i; ?>][id]" value="<?php echo esc_attr( $v['id'] ); ?>">
							<input type="text" name="variants[<?php echo $i; ?>][variant_key]" value="<?php echo esc_attr( $v['variant_key'] ); ?>" placeholder="control" <?php disabled( $is_locked ); ?>>
						</td>
						<td><input type="text" name="variants[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $v['label'] ); ?>" placeholder="Control" class="pbd-exp-variant-label" <?php disabled( $is_locked ); ?>></td>
						<td>
							<input type="number" name="variants[<?php echo $i; ?>][weight]" min="0" value="<?php echo esc_attr( $v['weight'] ); ?>" class="pbd-exp-weight" <?php disabled( $is_locked ); ?>>
							<span class="pbd-exp-variant-share"><?php echo (int) $share; ?>%</span>
						</td>
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
							<input type="text" name="variants[<?php echo $i; ?>][template_path]" value="<?php echo esc_attr( $v['template_path'] ); ?>" placeholder="page-variant.php" class="pbd-exp-template-path" <?php if ( 'redirect' === $v['variant_type'] ) echo 'hidden'; ?> <?php disabled( $is_locked ); ?>>
							<input type="text" name="variants[<?php echo $i; ?>][redirect_url]" value="<?php echo esc_attr( $v['redirect_url'] ); ?>" placeholder="/free-classes-v2/" class="pbd-exp-redirect-url" <?php if ( 'redirect' !== $v['variant_type'] ) echo 'hidden'; ?> <?php disabled( $is_locked ); ?>>
						</td>
						<td class="col-remove">
							<?php if ( ! $is_locked ) : ?>
								<button type="button" class="pbd-exp-remove-row" title="Remove variant" aria-label="Remove <?php echo esc_attr( $variant_label ); ?>">
									<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $is_locked ) : ?>
			<p class="pbd-exp-table-actions"><button type="button" class="button" id="pbd-exp-add-variant">+ Add variant</button></p>
		<?php endif; ?>
		<p class="pbd-exp-field__hint"><strong>Template</strong> swaps the page template file (child theme first, then parent). <strong>Redirect</strong> sends the visitor to another same-site URL. Off-site redirects are rejected on save.</p>
		<?php
	}

	private static function render_metrics_table( $metrics, $is_locked ) {
		?>
		<table class="pbd-exp-table pbd-exp-table-metrics" id="pbd-exp-metrics">
			<thead>
				<tr>
					<th>Key</th>
					<th>Name</th>
					<th>Event name (fired from page)</th>
					<th class="col-active">Active</th>
					<th class="col-remove" aria-label="Remove"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $metrics as $i => $m ) :
					$metric_label = ! empty( $m['name'] ) ? $m['name'] : ( ! empty( $m['metric_key'] ) ? $m['metric_key'] : 'metric ' . ( $i + 1 ) );
				?>
					<tr class="pbd-exp-metric-row">
						<td>
							<input type="hidden" name="metrics[<?php echo $i; ?>][id]" value="<?php echo esc_attr( isset( $m['id'] ) ? $m['id'] : 0 ); ?>">
							<input type="text" name="metrics[<?php echo $i; ?>][metric_key]" value="<?php echo esc_attr( $m['metric_key'] ); ?>" placeholder="opt_in" <?php disabled( $is_locked ); ?>>
						</td>
						<td><input type="text" name="metrics[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $m['name'] ); ?>" placeholder="Opt-in" <?php disabled( $is_locked ); ?>></td>
						<td><input type="text" name="metrics[<?php echo $i; ?>][event_name]" value="<?php echo esc_attr( $m['event_name'] ); ?>" placeholder="opt_in" <?php disabled( $is_locked ); ?>></td>
						<td class="col-active"><input type="checkbox" name="metrics[<?php echo $i; ?>][active]" value="1" <?php checked( ! empty( $m['active'] ) ); ?> <?php disabled( $is_locked ); ?>></td>
						<td class="col-remove">
							<?php if ( ! $is_locked ) : ?>
								<button type="button" class="pbd-exp-remove-row" title="Remove metric" aria-label="Remove <?php echo esc_attr( $metric_label ); ?>">
									<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $is_locked ) : ?>
			<p class="pbd-exp-table-actions"><button type="button" class="button" id="pbd-exp-add-metric">+ Add metric</button></p>
		<?php endif; ?>
		<p class="pbd-exp-field__hint"><strong>Key</strong> is for your records, <strong>Name</strong> shows up on the dashboard, <strong>Event name</strong> is what the page fires (snake_case). Inactive metrics stop counting but their history stays.</p>
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
