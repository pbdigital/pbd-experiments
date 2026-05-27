<?php
defined( 'ABSPATH' ) || exit;

/**
 * Past Experiments archive. Shows all concluded experiments, their frozen
 * snapshot, and the declared winner. Searchable by name or key.
 */
final class PBD_Exp_Admin_Archive {

	public static function render() {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$all_concluded = PBD_Exp_Repo::list_experiments( array( 'concluded' ) );

		$experiments = $all_concluded;
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$experiments = array_values( array_filter( $experiments, function ( $e ) use ( $needle ) {
				return false !== strpos( strtolower( $e['name'] ), $needle )
					|| false !== strpos( strtolower( $e['experiment_key'] ), $needle );
			} ) );
		}

		?>
		<div class="wrap pbd-exp-wrap">
			<h1 class="wp-heading-inline">Past Experiments</h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG ) ); ?>">&larr; Active experiments</a>
			<hr class="wp-header-end">

			<?php
			$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
			if ( 'deleted' === $message ) {
				echo '<div class="notice notice-success is-dismissible"><p>Experiment deleted.</p></div>';
			}
			?>

			<?php if ( empty( $all_concluded ) ) : ?>
				<div class="pbd-exp-empty">
					<h3>No concluded experiments yet</h3>
					<p>When you conclude a running experiment, its result snapshot is frozen and it appears here for future reference. Declare a winner so future-you remembers what won.</p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG ) ); ?>">Go to active experiments</a>
				</div>
			<?php else : ?>
				<form method="get" class="search-form" style="margin:16px 0 8px;display:flex;gap:8px;align-items:center;">
					<input type="hidden" name="page" value="<?php echo esc_attr( PBD_Exp_Admin::MENU_SLUG . '-archive' ); ?>">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by name or key">
					<button class="button" type="submit">Search</button>
					<?php if ( '' !== $search ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '-archive' ) ); ?>">Clear</a>
					<?php endif; ?>
					<span style="margin-left:auto;color:#646970;font-size:12px;">
						<?php printf( esc_html( _n( '%d concluded experiment', '%d concluded experiments', count( $all_concluded ), 'pbd-experiments' ) ), (int) count( $all_concluded ) ); ?>
					</span>
				</form>

				<?php if ( empty( $experiments ) ) : ?>
					<div class="pbd-exp-empty">
						<h3>No matches for &ldquo;<?php echo esc_html( $search ); ?>&rdquo;</h3>
						<p>Try a different name or experiment key.</p>
					</div>
				<?php else : ?>
					<?php foreach ( $experiments as $experiment ) : self::render_archived_experiment( $experiment ); endforeach; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_archived_experiment( $experiment ) {
		$snapshot_row = PBD_Exp_Repo::get_snapshot( $experiment['id'] );
		$snapshot     = $snapshot_row ? $snapshot_row['snapshot'] : null;
		$winner_id    = (int) $experiment['winning_variant_id'];

		$duration_label = '';
		if ( ! empty( $experiment['started_at'] ) && ! empty( $experiment['concluded_at'] ) ) {
			$days = max( 0, (int) floor( ( strtotime( $experiment['concluded_at'] ) - strtotime( $experiment['started_at'] ) ) / DAY_IN_SECONDS ) );
			$duration_label = 1 === $days ? '1 day' : ( number_format_i18n( $days ) . ' days' );
		}

		$edit_url    = admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '&action=edit&id=' . $experiment['id'] );
		$archive_url = admin_url( 'admin.php?page=' . PBD_Exp_Admin::MENU_SLUG . '-archive' );
		$key         = $experiment['experiment_key'];
		$delete_prompt = sprintf(
			'Permanently deleting "%s" removes its snapshot and all historical events. Type the experiment key "%s" to confirm.',
			$experiment['name'],
			$key
		);

		?>
		<div class="pbd-exp-archive-item">
			<h2>
				<?php echo esc_html( $experiment['name'] ); ?>
				<?php if ( $winner_id ) : ?>
					<span class="pbd-exp-winner-badge">winner declared</span>
				<?php endif; ?>
				<span class="actions">
					<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Open</a>
					<form method="post" style="display:inline;margin:0;">
						<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
						<input type="hidden" name="pbd_exp_action" value="clone_experiment">
						<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $experiment['id'] ); ?>">
						<button type="submit" class="button button-small">Clone</button>
					</form>
					<form method="post" class="pbd-exp-delete-form" style="display:inline;margin:0;" data-confirm-key="<?php echo esc_attr( $key ); ?>" data-confirm-prompt="<?php echo esc_attr( $delete_prompt ); ?>">
						<?php wp_nonce_field( PBD_Exp_Admin::NONCE_ACTION ); ?>
						<input type="hidden" name="pbd_exp_action" value="delete_experiment">
						<input type="hidden" name="experiment_id" value="<?php echo esc_attr( $experiment['id'] ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $archive_url ); ?>">
						<button type="submit" class="button button-small pbd-exp-btn-danger">Delete</button>
					</form>
				</span>
			</h2>
			<p class="pbd-exp-archive-meta">
				<span class="pbd-exp-status pbd-exp-status--concluded">Concluded</span>
				<code><?php echo esc_html( $experiment['experiment_key'] ); ?></code>
				<span>on <code><?php echo esc_html( $experiment['target_path'] ); ?></code></span>
				<?php if ( ! empty( $experiment['concluded_at'] ) ) : ?>
					<span>&middot; concluded <?php echo esc_html( mysql2date( get_option( 'date_format' ), $experiment['concluded_at'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( $duration_label ) : ?>
					<span>&middot; ran for <?php echo esc_html( $duration_label ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( ! $winner_id ) : ?>
				<div class="pbd-exp-help" style="margin:0 0 14px;">
					<strong>No winner declared.</strong> Open the experiment and pick the winning variant so future-you remembers what worked.
					<a href="<?php echo esc_url( $edit_url ); ?>">Declare winner &rarr;</a>
				</div>
			<?php endif; ?>

			<?php if ( ! $snapshot ) : ?>
				<p style="color:#646970;">No snapshot recorded for this experiment.</p>
			<?php else : ?>
				<table class="widefat striped pbd-exp-results">
					<thead>
						<tr>
							<th class="col-variant">Variant</th>
							<th>Visitors</th>
							<?php foreach ( $snapshot['active_metrics'] as $metric ) : ?>
								<th><?php echo esc_html( $metric['name'] ); ?></th>
								<th>Rate</th>
								<th>Lift vs control</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $snapshot['rows'] as $i => $row ) :
							$is_winner = $winner_id && $winner_id === (int) $row['variant_id'];
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
			<?php endif; ?>

			<?php if ( ! empty( $experiment['notes'] ) ) : ?>
				<h3 style="margin-top:18px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#646970;">Notes</h3>
				<div class="pbd-exp-notes" style="background:#f6f7f7;"><?php echo nl2br( esc_html( $experiment['notes'] ) ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
