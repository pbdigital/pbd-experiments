<?php
defined( 'ABSPATH' ) || exit;

/**
 * Past Experiments archive. Shows all concluded experiments, their frozen
 * snapshot, and the declared winner. Searchable by name or key.
 */
final class PBD_Exp_Admin_Archive {

	public static function render() {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$experiments = PBD_Exp_Repo::list_experiments( array( 'concluded' ) );

		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$experiments = array_values( array_filter( $experiments, function ( $e ) use ( $needle ) {
				return false !== strpos( strtolower( $e['name'] ), $needle )
					|| false !== strpos( strtolower( $e['experiment_key'] ), $needle );
			} ) );
		}

		?>
		<div class="wrap pbd-exp-wrap">
			<h1>Past Experiments</h1>
			<form method="get" class="search-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( PBD_Exp_Admin::MENU_SLUG . '-archive' ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name or key">
				<button class="button" type="submit">Search</button>
			</form>

			<?php if ( empty( $experiments ) ) : ?>
				<p>No concluded experiments yet.</p>
			<?php else : ?>
				<?php foreach ( $experiments as $experiment ) : self::render_archived_experiment( $experiment ); endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_archived_experiment( $experiment ) {
		$snapshot_row = PBD_Exp_Repo::get_snapshot( $experiment['id'] );
		$snapshot     = $snapshot_row ? $snapshot_row['snapshot'] : null;
		$winner_id    = (int) $experiment['winning_variant_id'];

		?>
		<div class="pbd-exp-archive-item" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0;">
			<h2>
				<?php echo esc_html( $experiment['name'] ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $experiment['id'] ) ); ?>" class="button button-small">View</a>
			</h2>
			<p>
				<code><?php echo esc_html( $experiment['experiment_key'] ); ?></code>
				on <code><?php echo esc_html( $experiment['target_path'] ); ?></code>
				&middot; concluded <?php echo esc_html( $experiment['concluded_at'] ); ?>
			</p>

			<?php if ( ! $snapshot ) : ?>
				<p>No snapshot recorded.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Variant</th>
							<th>Visitors</th>
							<?php foreach ( $snapshot['active_metrics'] as $metric ) : ?>
								<th><?php echo esc_html( $metric['name'] ); ?></th>
								<th>Rate</th>
								<th>Lift</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $snapshot['rows'] as $row ) : ?>
							<tr <?php if ( $winner_id && $winner_id === (int) $row['variant_id'] ) echo 'style="background:#fff8e5;"'; ?>>
								<td>
									<?php echo esc_html( $row['label'] ); ?>
									<code><?php echo esc_html( $row['variant_key'] ); ?></code>
									<?php if ( $winner_id && $winner_id === (int) $row['variant_id'] ) : ?>
										<span class="pbd-exp-winner-badge">winner</span>
									<?php endif; ?>
								</td>
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
			<?php endif; ?>

			<?php if ( ! empty( $experiment['notes'] ) ) : ?>
				<h3>Notes</h3>
				<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:8px 12px;"><?php echo nl2br( esc_html( $experiment['notes'] ) ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
