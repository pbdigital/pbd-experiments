<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-experiment dashboard: multi-metric, date-windowed, with sample ratio
 * mismatch (SRM) warning.
 */
final class PBD_Exp_Admin_Dashboard {

	const SRM_MIN_VISITORS = 500;          // per-arm minimum before flagging
	const SRM_DEVIATION_THRESHOLD = 0.05;  // 5 percentage points off configured weight

	const SEGMENT_MIN_VISITORS = 100;      // per-arm minimum before a slice is trusted; below this it is dimmed
	const SEGMENT_MAX_ROWS = 12;           // cap unbounded source/medium slices in the live view

	public static function render( $id ) {
		$experiment = PBD_Exp_Repo::get_experiment( $id );
		if ( ! $experiment ) {
			?>
			<div class="wrap pbd-exp-wrap">
				<h1>Experiment not found</h1>
				<div class="pbd-exp-empty">
					<h3>This experiment no longer exists</h3>
					<p>It may have been deleted. Head back to the list to pick another one.</p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG ) ); ?>">All experiments</a>
				</div>
			</div>
			<?php
			return;
		}

		$variants = PBD_Exp_Repo::get_variants( $id );
		$metrics  = PBD_Exp_Repo::get_metrics( $id );
		$segment  = self::selected_segment();
		$report   = self::build_report( $experiment, $variants, $metrics, self::date_window( $experiment ), array( $segment ) );

		$window_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$window_to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		$edit_url = admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $id );

		// Flash messages from transitions/saves landing on this URL
		$flash = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		$messages = array(
			'saved'   => array( 'success', 'Changes saved.' ),
			'started' => array( 'success', 'Experiment started.' ),
			'paused'  => array( 'success', 'Experiment paused.' ),
			'resumed' => array( 'success', 'Experiment resumed.' ),
		);
		?>
		<div class="wrap pbd-exp-wrap">
			<h1 class="wp-heading-inline">Dashboard: <?php echo esc_html( $experiment['name'] ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $edit_url ); ?>">Edit</a>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG ) ); ?>">&larr; All experiments</a>
			<hr class="wp-header-end">

			<?php
			if ( isset( $messages[ $flash ] ) ) {
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $flash ][0] ), esc_html( $messages[ $flash ][1] ) );
			}
			?>

			<p class="pbd-exp-page-meta">
				<span class="pbd-exp-status pbd-exp-status--<?php echo esc_attr( $experiment['status'] ); ?>"><?php echo esc_html( ucfirst( $experiment['status'] ) ); ?></span>
				<code><?php echo esc_html( $experiment['experiment_key'] ); ?></code>
				<span class="sep">&middot;</span>
				targets <code><?php echo esc_html( $experiment['target_path'] ); ?></code>
				<?php if ( ! empty( $experiment['started_at'] ) ) : ?>
					<span class="sep">&middot;</span> started <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $experiment['started_at'] ) ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $experiment['concluded_at'] ) ) : ?>
					<span class="sep">&middot;</span> concluded <?php echo esc_html( mysql2date( get_option( 'date_format' ), $experiment['concluded_at'] ) ); ?>
				<?php endif; ?>
			</p>

			<?php self::render_summary_stats( $experiment, $report ); ?>

			<?php if ( 'paused' === $experiment['status'] ) : ?>
				<div class="notice notice-warning inline" style="margin:16px 0;"><p><strong>This experiment is paused.</strong> No new visitors are being assigned. Returning visitors with a cookie still see their assigned variant.</p></div>
			<?php endif; ?>

			<form method="get" class="pbd-exp-date-window">
				<input type="hidden" name="page" value="<?php echo esc_attr( PBD_Exp_Admin::MENU_SLUG ); ?>">
				<input type="hidden" name="action" value="dashboard">
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
				<label>From <input type="date" name="from" value="<?php echo esc_attr( $window_from ); ?>"></label>
				<label>To <input type="date" name="to" value="<?php echo esc_attr( $window_to ); ?>"></label>
				<button class="button" type="submit">Apply window</button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=dashboard&id=' . $id ) ); ?>">Since start</a>
				<span style="margin-left:auto;color:#646970;font-size:12px;">Restrict to a date range without losing the underlying data.</span>
			</form>

			<?php if ( $report['srm_warning'] ) : ?>
				<div class="notice notice-warning"><p><strong>Sample ratio mismatch.</strong> Observed traffic split deviates from configured weights by more than <?php echo (int) ( self::SRM_DEVIATION_THRESHOLD * 100 ); ?>% with more than <?php echo (int) self::SRM_MIN_VISITORS; ?> visitors per arm. Common causes: caching, bot traffic, broken redirects. Investigate before trusting the numbers.</p></div>
			<?php endif; ?>

			<?php if ( 0 === $report['total_visitors'] && 'draft' === $experiment['status'] ) : ?>
				<div class="pbd-exp-empty" style="margin:16px 0;">
					<h3>No data yet, this experiment is still a draft</h3>
					<p>Start the experiment from the Edit screen to begin assigning variants to visitors. Results will appear here as traffic flows.</p>
					<a class="button button-primary" href="<?php echo esc_url( $edit_url ); ?>">Open Edit to start</a>
				</div>
			<?php elseif ( 0 === $report['total_visitors'] ) : ?>
				<div class="pbd-exp-empty" style="margin:16px 0;">
					<h3>No visitors recorded in this window</h3>
					<p>Either the page hasn't been hit by qualifying traffic, or the date window excludes all recorded assignments. Try widening the window above.</p>
				</div>
			<?php elseif ( ! empty( $report['active_metrics'] ) ) : ?>
				<?php self::render_results_table( $experiment, $report ); ?>
			<?php else : ?>
				<?php self::render_results_table( $experiment, $report ); ?>
				<div class="pbd-exp-help" style="margin-top:16px;">
					<strong>No active metrics yet.</strong> Visitor assignments are being recorded, but until you add a metric on the Edit screen there's nothing to measure conversion against.
					<a href="<?php echo esc_url( $edit_url . '#pbd-exp-metrics' ); ?>">Add a metric</a>.
				</div>
			<?php endif; ?>

			<?php if ( $report['total_visitors'] > 0 && ! empty( $report['active_metrics'] ) ) : ?>
				<?php self::render_segment_breakdown( $experiment, $report, $segment ); ?>
			<?php endif; ?>

			<?php self::render_dashboard_actions( $experiment ); ?>

			<?php if ( ! empty( $experiment['notes'] ) ) : ?>
				<div class="pbd-exp-card" style="margin-top:24px;">
					<div class="pbd-exp-card__header">
						<h2>Notes</h2>
					</div>
					<div class="pbd-exp-card__body"><?php echo nl2br( esc_html( $experiment['notes'] ) ); ?></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_summary_stats( $experiment, $report ) {
		$primary = ! empty( $report['active_metrics'] ) ? $report['active_metrics'][0] : null;
		$total_visitors = (int) $report['total_visitors'];

		// Suppress stat cards when there's nothing to show (draft, or active with no traffic yet).
		if ( 'draft' === $experiment['status'] || 0 === $total_visitors ) {
			$edit_url = admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . (int) $experiment['id'] );
			if ( 'draft' === $experiment['status'] ) {
				?>
				<div class="pbd-exp-stats-empty">
					<h3>Not started yet</h3>
					<p>Finish setup, then click <strong>Start experiment</strong> on the Edit screen. Numbers will appear here as visitors flow in.</p>
					<a class="button button-primary" href="<?php echo esc_url( $edit_url ); ?>">Open Edit to start</a>
				</div>
				<?php
			} else {
				?>
				<div class="pbd-exp-stats-empty">
					<h3>No visitors yet</h3>
					<p>The experiment is <?php echo esc_html( $experiment['status'] ); ?>, but nothing has been recorded in this window. Numbers will fill in as visitors hit the target page.</p>
				</div>
				<?php
			}
			return;
		}

		$total_primary_conversions = 0;
		$primary_rate = 0.0;
		if ( $primary ) {
			foreach ( $report['rows'] as $row ) {
				if ( isset( $row['metrics'][0] ) ) {
					$total_primary_conversions += (int) $row['metrics'][0]['conversions'];
				}
			}
			$primary_rate = $total_visitors > 0 ? ( $total_primary_conversions / $total_visitors ) * 100 : 0;
		}

		$days_running = null;
		if ( ! empty( $experiment['started_at'] ) ) {
			$end = ! empty( $experiment['concluded_at'] ) ? $experiment['concluded_at'] : current_time( 'mysql' );
			$days_running = max( 0, (int) floor( ( strtotime( $end ) - strtotime( $experiment['started_at'] ) ) / DAY_IN_SECONDS ) );
		}
		?>
		<div class="pbd-exp-stats">
			<div class="pbd-exp-stat">
				<p class="pbd-exp-stat__label">Visitors</p>
				<div class="pbd-exp-stat__value"><?php echo esc_html( number_format_i18n( $total_visitors ) ); ?></div>
				<div class="pbd-exp-stat__hint">Across all variants in window</div>
			</div>

			<?php if ( $primary ) : ?>
				<div class="pbd-exp-stat">
					<p class="pbd-exp-stat__label"><?php echo esc_html( $primary['name'] ); ?> (primary)</p>
					<div class="pbd-exp-stat__value"><?php echo esc_html( number_format_i18n( $total_primary_conversions ) ); ?></div>
					<div class="pbd-exp-stat__hint"><?php echo esc_html( number_format_i18n( $primary_rate, 2 ) ); ?>% headline rate</div>
				</div>
			<?php else : ?>
				<div class="pbd-exp-stat">
					<p class="pbd-exp-stat__label">Primary metric</p>
					<div class="pbd-exp-stat__value" style="color:#8c8f94;">&mdash;</div>
					<div class="pbd-exp-stat__hint">Add a metric on Edit</div>
				</div>
			<?php endif; ?>

			<div class="pbd-exp-stat">
				<p class="pbd-exp-stat__label">Running for</p>
				<div class="pbd-exp-stat__value">
					<?php echo null === $days_running ? '&mdash;' : ( 1 === $days_running ? '1 day' : esc_html( number_format_i18n( $days_running ) . ' days' ) ); ?>
				</div>
				<div class="pbd-exp-stat__hint">
					<?php echo null === $days_running ? 'Not started yet' : ( ! empty( $experiment['concluded_at'] ) ? 'Final' : 'Since start' ); ?>
				</div>
			</div>

			<div class="pbd-exp-stat">
				<p class="pbd-exp-stat__label">Variants</p>
				<div class="pbd-exp-stat__value"><?php echo (int) count( $report['rows'] ); ?></div>
				<div class="pbd-exp-stat__hint">Active arms in the split</div>
			</div>
		</div>
		<?php
	}

	private static function render_results_table( $experiment, $report ) {
		?>
		<table class="widefat striped pbd-exp-results">
			<thead>
				<tr>
					<th class="col-variant">Variant</th>
					<th>Weight</th>
					<th>Visitors</th>
					<?php foreach ( $report['active_metrics'] as $metric ) : ?>
						<th><?php echo esc_html( $metric['name'] ); ?></th>
						<th>Rate</th>
						<th>Lift vs control</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report['rows'] as $i => $row ) :
					$is_winner = ! empty( $experiment['winning_variant_id'] ) && (int) $experiment['winning_variant_id'] === (int) $row['variant_id'];
					$is_control = 0 === $i;
					$row_classes = array();
					if ( $is_winner ) $row_classes[] = 'row-winner';
					if ( $is_control ) $row_classes[] = 'row-control';
				?>
					<tr<?php if ( $row_classes ) echo ' class="' . esc_attr( implode( ' ', $row_classes ) ) . '"'; ?>>
						<td class="col-variant">
							<strong><?php echo esc_html( $row['label'] ); ?></strong>
							<code><?php echo esc_html( $row['variant_key'] ); ?></code>
							<?php if ( $is_control ) : ?><em>baseline</em><?php endif; ?>
							<?php if ( $is_winner ) : ?>
								<span class="pbd-exp-winner-badge">winner</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['weight'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?></td>
						<?php foreach ( $row['metrics'] as $metric_row ) : ?>
							<td><?php echo esc_html( number_format_i18n( $metric_row['conversions'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $metric_row['rate'] * 100, 2 ) ); ?>%</td>
							<td>
								<?php
								if ( null === $metric_row['lift'] ) {
									echo '<span class="pbd-exp-lift--flat">&mdash;</span>';
								} else {
									$lift = (float) $metric_row['lift'];
									$cls  = $lift > 0.5 ? 'pbd-exp-lift--up' : ( $lift < -0.5 ? 'pbd-exp-lift--down' : 'pbd-exp-lift--flat' );
									$sign = $lift > 0 ? '+' : '';
									printf( '<span class="%s">%s%s%%</span>', esc_attr( $cls ), esc_html( $sign ), esc_html( number_format_i18n( $lift, 2 ) ) );
								}
								?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<?php
			$unattributed = isset( $report['unattributed'] ) ? $report['unattributed'] : array();
			$has_unattributed = false;
			foreach ( $unattributed as $count ) {
				if ( (int) $count > 0 ) { $has_unattributed = true; break; }
			}
			if ( $has_unattributed ) :
			?>
			<tfoot>
				<tr class="pbd-exp-results__unattributed">
					<td colspan="3"><strong>Unattributed</strong> <span title="Conversions recorded without a variant. Usually a thank-you-page shortcode firing for visitors whose cookie did not survive a cross-domain redirect. Switch this metric to a form trigger on the test page to attribute them.">(no variant)</span></td>
					<?php foreach ( $report['active_metrics'] as $mi => $metric ) : ?>
						<td><?php echo esc_html( number_format_i18n( isset( $unattributed[ $mi ] ) ? (int) $unattributed[ $mi ] : 0 ) ); ?></td>
						<td>&mdash;</td>
						<td>&mdash;</td>
					<?php endforeach; ?>
				</tr>
			</tfoot>
			<?php endif; ?>
		</table>
		<?php if ( $has_unattributed ) : ?>
			<p class="pbd-exp-help" style="margin-top:8px;">
				<strong>Some conversions could not be attributed to a variant.</strong>
				They were recorded but with no variant assignment, so they are not in the per-variant counts above. This usually means the conversion is measured on a separate page (e.g. a thank-you-page shortcode) that visitors reach after a cross-domain redirect, where the visitor cookie is lost. Measure the conversion as a <em>form</em> trigger on the test page itself so it is attributed.
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Traffic-source breakdown: a tab selector plus one compact control-vs-variant
	 * sub-table per slice. Diagnostic only; thin slices are dimmed and badged, and
	 * a standing caution warns against declaring winners on small slices.
	 */
	private static function render_segment_breakdown( $experiment, $report, $segment ) {
		$data = isset( $report['segments'][ $segment ] ) ? $report['segments'][ $segment ] : null;
		$primary_name = isset( $report['segment_meta']['primary_name'] ) ? $report['segment_meta']['primary_name'] : 'Conversions';

		$id   = (int) $experiment['id'];
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		$base_args = array( 'page' => PBD_Exp_Admin::MENU_SLUG, 'action' => 'dashboard', 'id' => $id );
		if ( '' !== $from ) { $base_args['from'] = $from; }
		if ( '' !== $to ) { $base_args['to'] = $to; }

		$tabs = array(
			'channel' => 'Channel',
			'source'  => 'Source',
			'medium'  => 'Medium',
			'device'  => 'Device',
		);
		?>
		<div class="pbd-exp-card pbd-exp-segments" style="margin-top:24px;">
			<div class="pbd-exp-card__header">
				<h2>Break down by traffic source</h2>
			</div>
			<div class="pbd-exp-card__body">
				<div class="pbd-exp-segment-selector">
					<?php foreach ( $tabs as $key => $label ) :
						$url = admin_url( 'admin.php?' . http_build_query( array_merge( $base_args, array( 'segment' => $key ) ) ) );
						?>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $segment === $key ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>

				<p class="pbd-exp-segment-note">
					Segments are diagnostic. Don't declare a winner on a thin slice. Small samples and many comparisons make these rates noisy. Treat a within-segment difference as a lead to investigate, not a result.
				</p>

				<?php if ( ! $data || empty( $data['values'] ) ) : ?>
					<p class="pbd-exp-help">No <?php echo esc_html( strtolower( self::segment_label( $segment ) ) ); ?> data recorded in this window yet.</p>
				<?php else : ?>
					<?php foreach ( $data['values'] as $slice ) : ?>
						<div class="pbd-exp-segment-slice<?php echo $slice['low_sample'] ? ' pbd-exp-segment--thin' : ''; ?>">
							<div class="pbd-exp-segment-slice__head">
								<strong><?php echo esc_html( $slice['segment'] ); ?></strong>
								<span class="pbd-exp-segment-count"><?php echo esc_html( number_format_i18n( $slice['visitors'] ) ); ?> visitors</span>
								<?php if ( $slice['low_sample'] ) : ?>
									<span class="pbd-exp-segment-badge" title="At least one arm has fewer than <?php echo (int) self::SEGMENT_MIN_VISITORS; ?> visitors. Rates here are too noisy to trust.">low sample</span>
								<?php endif; ?>
								<?php if ( ! empty( $slice['is_unknown'] ) ) : ?>
									<span class="pbd-exp-segment-note-inline">predates source tracking</span>
								<?php endif; ?>
							</div>
							<table class="widefat striped pbd-exp-results pbd-exp-segment-table">
								<thead>
									<tr>
										<th class="col-variant">Variant</th>
										<th>Visitors</th>
										<th><?php echo esc_html( $primary_name ); ?></th>
										<th>Rate</th>
										<th>Lift vs control</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $slice['rows'] as $i => $row ) :
										$is_control = 0 === $i;
									?>
										<tr<?php echo $is_control ? ' class="row-control"' : ''; ?>>
											<td class="col-variant">
												<strong><?php echo esc_html( $row['label'] ); ?></strong>
												<code><?php echo esc_html( $row['variant_key'] ); ?></code>
												<?php if ( $is_control ) : ?><em>baseline</em><?php endif; ?>
											</td>
											<td><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $row['conversions'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $row['rate'] * 100, 2 ) ); ?>%</td>
											<td>
												<?php
												if ( null === $row['lift'] ) {
													echo '<span class="pbd-exp-lift--flat">&mdash;</span>';
												} else {
													$lift = (float) $row['lift'];
													$cls  = $lift > 0.5 ? 'pbd-exp-lift--up' : ( $lift < -0.5 ? 'pbd-exp-lift--down' : 'pbd-exp-lift--flat' );
													$sign = $lift > 0 ? '+' : '';
													printf( '<span class="%s">%s%s%%</span>', esc_attr( $cls ), esc_html( $sign ), esc_html( number_format_i18n( $lift, 2 ) ) );
												}
												?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_dashboard_actions( $experiment ) {
		$status = $experiment['status'];
		if ( ! in_array( $status, array( 'active', 'paused' ), true ) ) {
			return;
		}
		$id = (int) $experiment['id'];
		?>
		<div class="pbd-exp-status-bar" style="margin-top:24px;">
			<span class="pbd-exp-status-bar__label">Actions</span>

			<?php if ( 'active' === $status ) : ?>
				<form method="post" style="margin:0;">
					<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
					<input type="hidden" name="pbd_exp_action" value="transition_status">
					<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $id ); ?>">
					<input type="hidden" name="new_status" value="paused">
					<button type="submit" class="button">Pause</button>
				</form>
			<?php else : ?>
				<form method="post" style="margin:0;">
					<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
					<input type="hidden" name="pbd_exp_action" value="transition_status">
					<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $id ); ?>">
					<input type="hidden" name="new_status" value="active">
					<button type="submit" class="button button-primary">Resume</button>
				</form>
			<?php endif; ?>

			<form method="post" style="margin:0;" onsubmit="return confirm('Concluding freezes the result snapshot and moves the experiment to Past Experiments. Continue?');">
				<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
				<input type="hidden" name="pbd_exp_action" value="transition_status">
				<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $id ); ?>">
				<input type="hidden" name="new_status" value="concluded">
				<button type="submit" class="button button-primary pbd-exp-btn-conclude">Conclude experiment</button>
			</form>

			<span class="spacer"></span>
			<p class="pbd-exp-hint">Concluding freezes a snapshot of the current numbers and moves the experiment to Past Experiments. You'll declare the winner there.</p>
		</div>
		<?php
	}

	public static function build_snapshot( $experiment ) {
		$variants = PBD_Exp_Repo::get_variants( $experiment['id'] );
		$metrics  = PBD_Exp_Repo::get_metrics( $experiment['id'] );
		// Freeze only the bounded-cardinality breakdowns (channel + device); the
		// unbounded source/medium tables stay live-only so the snapshot JSON stays small.
		return self::build_report( $experiment, $variants, $metrics, array( 'from' => null, 'to' => null ), array( 'channel', 'device' ) );
	}

	private static function selected_segment() {
		$seg = isset( $_GET['segment'] ) ? sanitize_key( wp_unslash( $_GET['segment'] ) ) : 'channel';
		// referrer_host is a valid stored column but excluded from the UI selector (too noisy).
		$allowed = array( 'channel', 'source', 'medium', 'device' );
		return in_array( $seg, $allowed, true ) ? $seg : 'channel';
	}

	private static function segment_label( $col ) {
		$labels = array(
			'channel'       => 'Channel',
			'source'        => 'Source',
			'medium'        => 'Medium',
			'device'        => 'Device',
			'referrer_host' => 'Referrer',
		);
		return isset( $labels[ $col ] ) ? $labels[ $col ] : ucfirst( $col );
	}

	private static function date_window( $experiment ) {
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		return array(
			'from' => $from ? $from . ' 00:00:00' : ( ! empty( $experiment['started_at'] ) ? $experiment['started_at'] : null ),
			'to'   => $to ? $to . ' 23:59:59' : null,
		);
	}

	private static function build_report( $experiment, $variants, $metrics, $window, $segment_cols = array() ) {
		$active_metrics = array_values( array_filter( $metrics, function ( $m ) { return ! empty( $m['active'] ); } ) );

		$total_weight = 0;
		foreach ( $variants as $v ) {
			$total_weight += max( 0, (int) $v['weight'] );
		}

		$total_visitors = 0;
		$per_variant_visitors = array();
		foreach ( $variants as $v ) {
			$count = PBD_Exp_Repo::count_assignments( (int) $experiment['id'], (int) $v['id'], $window['from'], $window['to'] );
			$per_variant_visitors[ $v['id'] ] = $count;
			$total_visitors += $count;
		}

		$rows = array();
		$baseline_metric_rates = array();

		foreach ( $variants as $i => $variant ) {
			$visitors = $per_variant_visitors[ $variant['id'] ];
			$metric_rows = array();

			foreach ( $active_metrics as $metric_index => $metric ) {
				$conversions = PBD_Exp_Repo::count_conversions(
					(int) $experiment['id'],
					(int) $variant['id'],
					$metric['event_name'],
					$window['from'],
					$window['to']
				);
				$rate = $visitors > 0 ? $conversions / $visitors : 0;

				if ( 0 === $i ) {
					$baseline_metric_rates[ $metric_index ] = $rate;
				}
				$baseline = $baseline_metric_rates[ $metric_index ] ?? 0;
				$lift = $baseline > 0 ? ( ( $rate - $baseline ) / $baseline ) * 100 : null;

				$metric_rows[] = array(
					'event_name'  => $metric['event_name'],
					'conversions' => $conversions,
					'rate'        => $rate,
					'lift'        => 0 === $i ? null : $lift,
				);
			}

			$rows[] = array(
				'variant_id'  => (int) $variant['id'],
				'variant_key' => $variant['variant_key'],
				'label'       => $variant['label'],
				'weight'      => (int) $variant['weight'],
				'visitors'    => $visitors,
				'metrics'     => $metric_rows,
			);
		}

		// Conversions recorded with no variant assignment (e.g. a thank-you-page
		// shortcode fired by a visitor whose cookie did not survive a cross-domain
		// hop). These are invisible to the per-variant counts above, so surface
		// them explicitly: a broken attribution should read as a number, not zero.
		$unattributed = array();
		foreach ( $active_metrics as $mi => $metric ) {
			$unattributed[ $mi ] = PBD_Exp_Repo::count_unattributed_conversions(
				(int) $experiment['id'],
				$metric['event_name'],
				$window['from'],
				$window['to']
			);
		}

		// Traffic-source breakdowns. Built only against the primary metric
		// (active_metrics[0]) and only when there is traffic to slice. Each
		// requested column produces a set of slices comparing control vs variants
		// within that segment. See build_segment().
		$segments      = array();
		$primary_event = '';
		$primary_name  = '';
		if ( ! empty( $active_metrics ) && ! empty( $segment_cols ) && $total_visitors > 0 ) {
			$primary_event = $active_metrics[0]['event_name'];
			$primary_name  = $active_metrics[0]['name'];
			foreach ( $segment_cols as $col ) {
				$segments[ $col ] = self::build_segment( $experiment, $variants, $primary_event, $col, $window );
			}
		}

		return array(
			'active_metrics' => $active_metrics,
			'rows'           => $rows,
			'total_visitors' => $total_visitors,
			'unattributed'   => $unattributed,
			'srm_warning'    => self::srm_warning( $variants, $per_variant_visitors, $total_weight, $total_visitors ),
			'segments'       => $segments,
			'segment_meta'   => array(
				'primary_event' => $primary_event,
				'primary_name'  => $primary_name,
			),
		);
	}

	/**
	 * Pivot the grouped visitor/conversion counts for one segment column into
	 * per-slice control-vs-variant rows. Visitors and conversions both key off
	 * the assignment's first-touch source, so a slice is internally consistent.
	 */
	private static function build_segment( $experiment, $variants, $primary_event, $col, $window ) {
		$assign_rows = PBD_Exp_Repo::count_assignments_by_segment( (int) $experiment['id'], $col, $window['from'], $window['to'] );
		$conv_rows   = PBD_Exp_Repo::count_conversions_by_segment( (int) $experiment['id'], $primary_event, $col, $window['from'], $window['to'] );

		$vis = array();            // [variant_id][segment] = visitors
		$segment_totals = array(); // segment => total visitors across variants
		foreach ( (array) $assign_rows as $r ) {
			$vid = (int) $r['variant_id'];
			$seg = (string) $r['segment'];
			$n   = (int) $r['n'];
			$vis[ $vid ][ $seg ] = $n;
			$segment_totals[ $seg ] = ( isset( $segment_totals[ $seg ] ) ? $segment_totals[ $seg ] : 0 ) + $n;
		}

		$conv = array();           // [variant_id][segment] = conversions
		foreach ( (array) $conv_rows as $r ) {
			$conv[ (int) $r['variant_id'] ][ (string) $r['segment'] ] = (int) $r['n'];
		}

		// Order: real slices by total visitors desc, then the empty "Unknown"
		// bucket (pre-feature assignments) pinned last regardless of volume.
		$has_unknown = array_key_exists( '', $segment_totals );
		unset( $segment_totals[''] );
		arsort( $segment_totals );
		$seg_keys = array_keys( $segment_totals );

		// Unbounded dimensions (source/medium) are truncated to keep the live view readable.
		$bounded = in_array( $col, array( 'channel', 'device' ), true );
		if ( ! $bounded && count( $seg_keys ) > self::SEGMENT_MAX_ROWS ) {
			$seg_keys = array_slice( $seg_keys, 0, self::SEGMENT_MAX_ROWS );
		}
		if ( $has_unknown ) {
			$seg_keys[] = '';
		}

		$values = array();
		foreach ( $seg_keys as $seg ) {
			$slice_rows     = array();
			$slice_visitors = 0;
			$baseline_rate  = null;
			$low_sample     = false;

			foreach ( $variants as $i => $variant ) {
				$vid  = (int) $variant['id'];
				$v    = isset( $vis[ $vid ][ $seg ] ) ? (int) $vis[ $vid ][ $seg ] : 0;
				$c    = isset( $conv[ $vid ][ $seg ] ) ? (int) $conv[ $vid ][ $seg ] : 0;
				$rate = $v > 0 ? $c / $v : 0;

				if ( 0 === $i ) {
					$baseline_rate = $rate;
					$lift = null;
				} else {
					$lift = ( null !== $baseline_rate && $baseline_rate > 0 ) ? ( ( $rate - $baseline_rate ) / $baseline_rate ) * 100 : null;
				}

				if ( $v < self::SEGMENT_MIN_VISITORS ) {
					$low_sample = true;
				}
				$slice_visitors += $v;

				$slice_rows[] = array(
					'variant_id'  => $vid,
					'variant_key' => $variant['variant_key'],
					'label'       => $variant['label'],
					'visitors'    => $v,
					'conversions' => $c,
					'rate'        => $rate,
					'lift'        => $lift,
				);
			}

			$values[] = array(
				'segment'    => '' === $seg ? 'Unknown' : $seg,
				'is_unknown' => '' === $seg,
				'visitors'   => $slice_visitors,
				'low_sample' => $low_sample,
				'rows'       => $slice_rows,
			);
		}

		return array(
			'col'    => $col,
			'label'  => self::segment_label( $col ),
			'values' => $values,
		);
	}

	private static function srm_warning( $variants, $per_variant_visitors, $total_weight, $total_visitors ) {
		if ( $total_weight <= 0 || count( $variants ) < 2 ) {
			return false;
		}

		// All arms need to clear the minimum sample size; otherwise SRM is meaningless noise.
		foreach ( $variants as $v ) {
			if ( ( $per_variant_visitors[ $v['id'] ] ?? 0 ) < self::SRM_MIN_VISITORS ) {
				return false;
			}
		}

		foreach ( $variants as $v ) {
			$expected_share = max( 0, (int) $v['weight'] ) / $total_weight;
			$observed_share = $total_visitors > 0 ? ( $per_variant_visitors[ $v['id'] ] / $total_visitors ) : 0;
			if ( abs( $observed_share - $expected_share ) >= self::SRM_DEVIATION_THRESHOLD ) {
				return true;
			}
		}

		return false;
	}
}
