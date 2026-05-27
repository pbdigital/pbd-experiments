<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-experiment dashboard: multi-metric, date-windowed, with sample ratio
 * mismatch (SRM) warning.
 */
final class PBD_Exp_Admin_Dashboard {

	const SRM_MIN_VISITORS = 500;          // per-arm minimum before flagging
	const SRM_DEVIATION_THRESHOLD = 0.05;  // 5 percentage points off configured weight

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
		$report   = self::build_report( $experiment, $variants, $metrics, self::date_window( $experiment ) );

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

			<?php self::render_dashboard_actions( $experiment ); ?>

			<?php if ( ! empty( $experiment['notes'] ) ) : ?>
				<h2 style="margin-top:28px;">Notes</h2>
				<div class="pbd-exp-notes"><?php echo nl2br( esc_html( $experiment['notes'] ) ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_summary_stats( $experiment, $report ) {
		$primary = ! empty( $report['active_metrics'] ) ? $report['active_metrics'][0] : null;
		$total_visitors = (int) $report['total_visitors'];

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
		</table>
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
		return self::build_report( $experiment, $variants, $metrics, array( 'from' => null, 'to' => null ) );
	}

	private static function date_window( $experiment ) {
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		return array(
			'from' => $from ? $from . ' 00:00:00' : ( ! empty( $experiment['started_at'] ) ? $experiment['started_at'] : null ),
			'to'   => $to ? $to . ' 23:59:59' : null,
		);
	}

	private static function build_report( $experiment, $variants, $metrics, $window ) {
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

		return array(
			'active_metrics' => $active_metrics,
			'rows'           => $rows,
			'total_visitors' => $total_visitors,
			'srm_warning'    => self::srm_warning( $variants, $per_variant_visitors, $total_weight, $total_visitors ),
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
