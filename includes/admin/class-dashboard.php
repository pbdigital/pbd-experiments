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
			echo '<div class="wrap"><h1>Experiment not found</h1></div>';
			return;
		}

		$variants = PBD_Exp_Repo::get_variants( $id );
		$metrics  = PBD_Exp_Repo::get_metrics( $id );
		$report   = self::build_report( $experiment, $variants, $metrics, self::date_window( $experiment ) );

		$window_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$window_to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		?>
		<div class="wrap pbd-exp-wrap">
			<h1 class="wp-heading-inline">Dashboard: <?php echo esc_html( $experiment['name'] ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $id ) ); ?>">Edit</a>
			<hr class="wp-header-end">

			<p>
				<code><?php echo esc_html( $experiment['experiment_key'] ); ?></code>
				<span class="pbd-exp-status pbd-exp-status--<?php echo esc_attr( $experiment['status'] ); ?>"><?php echo esc_html( ucfirst( $experiment['status'] ) ); ?></span>
				on <code><?php echo esc_html( $experiment['target_path'] ); ?></code>
				<?php if ( ! empty( $experiment['started_at'] ) ) : ?>
					&middot; started <?php echo esc_html( $experiment['started_at'] ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $experiment['concluded_at'] ) ) : ?>
					&middot; concluded <?php echo esc_html( $experiment['concluded_at'] ); ?>
				<?php endif; ?>
			</p>

			<form method="get" class="pbd-exp-date-window">
				<input type="hidden" name="page" value="<?php echo esc_attr( PBD_Exp_Admin::MENU_SLUG ); ?>">
				<input type="hidden" name="action" value="dashboard">
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
				<label>From <input type="date" name="from" value="<?php echo esc_attr( $window_from ); ?>"></label>
				<label>To <input type="date" name="to" value="<?php echo esc_attr( $window_to ); ?>"></label>
				<button class="button" type="submit">Apply window</button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=dashboard&id=' . $id ) ); ?>">Since start</a>
			</form>

			<?php if ( $report['srm_warning'] ) : ?>
				<div class="notice notice-warning"><p><strong>Sample ratio mismatch.</strong> Observed traffic split deviates from configured weights by more than <?php echo (int) ( self::SRM_DEVIATION_THRESHOLD * 100 ); ?>% with &gt;<?php echo (int) self::SRM_MIN_VISITORS; ?> visitors per arm. Common causes: caching, bot traffic, broken redirects. Investigate before trusting the numbers.</p></div>
			<?php endif; ?>

			<table class="widefat striped pbd-exp-results">
				<thead>
					<tr>
						<th>Variant</th>
						<th>Weight</th>
						<th>Visitors</th>
						<?php foreach ( $report['active_metrics'] as $metric ) : ?>
							<th><?php echo esc_html( $metric['name'] ); ?></th>
							<th>Rate</th>
							<th>Lift</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['rows'] as $row ) : ?>
						<tr <?php if ( ! empty( $experiment['winning_variant_id'] ) && (int) $experiment['winning_variant_id'] === (int) $row['variant_id'] ) echo 'style="background:#fff8e5;"'; ?>>
							<td>
								<?php echo esc_html( $row['label'] ); ?>
								<code><?php echo esc_html( $row['variant_key'] ); ?></code>
								<?php if ( ! empty( $experiment['winning_variant_id'] ) && (int) $experiment['winning_variant_id'] === (int) $row['variant_id'] ) : ?>
									<span class="pbd-exp-winner-badge">winner</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['weight'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?></td>
							<?php foreach ( $row['metrics'] as $metric_row ) : ?>
								<td><?php echo esc_html( number_format_i18n( $metric_row['conversions'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $metric_row['rate'] * 100, 2 ) ); ?>%</td>
								<td><?php echo null === $metric_row['lift'] ? '&mdash;' : esc_html( number_format_i18n( $metric_row['lift'], 2 ) . '%' ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( empty( $report['active_metrics'] ) ) : ?>
				<p class="description">No active metrics configured. Add one on the Edit screen to see conversion rates.</p>
			<?php endif; ?>

			<?php if ( ! empty( $experiment['notes'] ) ) : ?>
				<h2>Notes</h2>
				<div style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-width:900px;"><?php echo nl2br( esc_html( $experiment['notes'] ) ); ?></div>
			<?php endif; ?>
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
