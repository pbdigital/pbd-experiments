<?php
defined( 'ABSPATH' ) || exit;

final class PBD_Exp_Events {

	/**
	 * Record an event. Used by REST endpoint, shortcode, and internal dispatcher
	 * (for the auto-fired `experiment_viewed` on a target-page view).
	 *
	 * @param string $experiment_key
	 * @param string $event_name
	 * @param array  $metadata
	 * @param bool   $once         If true, deduplicate per-visitor per-event.
	 * @param string $variant_key  Optional. If the visitor has no assignment yet,
	 *                             attach this variant retroactively.
	 *
	 * @return array|WP_Error
	 */
	public static function record( $experiment_key, $event_name, $metadata = array(), $once = false, $variant_key = '' ) {
		$experiment_key = sanitize_key( $experiment_key );
		$event_name     = sanitize_key( $event_name );

		if ( ! $experiment_key || ! $event_name ) {
			return new WP_Error( 'pbd_exp_missing', 'Experiment and event are required.' );
		}

		$experiment = PBD_Exp_Repo::get_experiment_by_key( $experiment_key );
		if ( ! $experiment ) {
			return new WP_Error( 'pbd_exp_unknown', 'Experiment not found.' );
		}

		if ( PBD_Exp_Exclusions::should_exclude( $experiment ) ) {
			return array( 'excluded' => true );
		}

		$visitor_id    = PBD_Exp_Visitor::get_id();
		$experiment_id = (int) $experiment['id'];
		$assignment    = PBD_Exp_Repo::get_assignment( $visitor_id, $experiment_id );

		// Off-target events (e.g. shortcode on a thank-you page): attach existing
		// assignment if there is one, or honour an explicit variant pin from JS.
		if ( ! $assignment && $variant_key ) {
			$variant = PBD_Exp_Repo::get_variant_by_key( $experiment_id, sanitize_key( $variant_key ) );
			if ( $variant ) {
				$assignment = PBD_Exp_Assignment::assign_specific( $visitor_id, $experiment_id, $variant );
			}
		}

		if ( $once && PBD_Exp_Repo::event_already_recorded( $visitor_id, $experiment_id, $event_name ) ) {
			return array( 'duplicate' => true );
		}

		$url      = isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] )
			? ( is_ssl() ? 'https://' : 'http://' ) . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';

		$event_id = PBD_Exp_Repo::insert_event(
			array(
				'visitor_id'    => $visitor_id,
				'experiment_id' => $experiment_id,
				'variant_id'    => $assignment ? (int) $assignment['variant_id'] : null,
				'event_name'    => $event_name,
				'url'           => esc_url_raw( $url ),
				'referrer'      => esc_url_raw( $referrer ),
				'metadata'      => wp_json_encode( self::sanitize_metadata( $metadata ) ),
				'occurred_at'   => current_time( 'mysql' ),
			)
		);

		return array( 'event_id' => $event_id );
	}

	private static function sanitize_metadata( $metadata ) {
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$clean = array();
		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! $key ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}
}
