<?php
defined( 'ABSPATH' ) || exit;

final class PBD_Exp_Assignment {

	/**
	 * Look up or create the visitor's assignment for this experiment.
	 * Respects ?pbd_exp_variant=key forcing for QA, and ?pbd_exp_clear=1 to reset.
	 *
	 * @return array|null Joined assignment row, or null if no variants are configured.
	 */
	public static function resolve( $experiment ) {
		$visitor_id    = PBD_Exp_Visitor::get_id();
		$experiment_id = (int) $experiment['id'];

		if ( self::should_clear() ) {
			self::clear( $visitor_id, $experiment_id );
		}

		$forced_key = self::forced_variant_key();
		if ( $forced_key ) {
			$variant = PBD_Exp_Repo::get_variant_by_key( $experiment_id, $forced_key );
			if ( $variant ) {
				return self::assign_specific( $visitor_id, $experiment_id, $variant );
			}
		}

		$existing = PBD_Exp_Repo::get_assignment( $visitor_id, $experiment_id );
		if ( $existing ) {
			return $existing;
		}

		$variant = self::pick_variant( $experiment_id );
		if ( ! $variant ) {
			return null;
		}

		// First touch: capture the traffic source now, while $_GET (utm/click ids),
		// the referrer, and the UA are all still on this landing request and before
		// a redirect variant 302s. Written once; return visits reuse the row above.
		$source = PBD_Exp_Traffic_Source::detect();
		PBD_Exp_Repo::insert_assignment( $visitor_id, $experiment_id, (int) $variant['id'], $source );
		PBD_Exp_Visitor::set_cookie( $visitor_id, (int) $experiment['cookie_days'] );

		return PBD_Exp_Repo::get_assignment( $visitor_id, $experiment_id );
	}

	/**
	 * Attempt to attach an existing assignment when recording an event off-target
	 * (e.g. a thank-you page firing a purchase event). Never creates a new assignment.
	 *
	 * @return array|null
	 */
	public static function lookup_only( $visitor_id, $experiment_id ) {
		return PBD_Exp_Repo::get_assignment( $visitor_id, (int) $experiment_id );
	}

	public static function assign_specific( $visitor_id, $experiment_id, $variant ) {
		$existing = PBD_Exp_Repo::get_assignment( $visitor_id, $experiment_id );
		if ( $existing && (int) $existing['variant_id'] === (int) $variant['id'] ) {
			return $existing;
		}

		if ( $existing ) {
			global $wpdb;
			$wpdb->update(
				PBD_Exp_Schema::table( 'assignments' ),
				array(
					'variant_id'  => (int) $variant['id'],
					'assigned_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing['id'] )
			);
		} else {
			PBD_Exp_Repo::insert_assignment( $visitor_id, $experiment_id, (int) $variant['id'] );
		}

		return PBD_Exp_Repo::get_assignment( $visitor_id, $experiment_id );
	}

	private static function clear( $visitor_id, $experiment_id ) {
		global $wpdb;
		$wpdb->delete(
			PBD_Exp_Schema::table( 'assignments' ),
			array(
				'visitor_id'    => $visitor_id,
				'experiment_id' => (int) $experiment_id,
			),
			array( '%s', '%d' )
		);
	}

	private static function pick_variant( $experiment_id ) {
		$variants = PBD_Exp_Repo::get_variants( $experiment_id );
		if ( empty( $variants ) ) {
			return null;
		}

		$total = 0;
		foreach ( $variants as $variant ) {
			$total += max( 0, (int) $variant['weight'] );
		}

		if ( $total <= 0 ) {
			return $variants[0];
		}

		$roll    = wp_rand( 1, $total );
		$running = 0;
		foreach ( $variants as $variant ) {
			$running += max( 0, (int) $variant['weight'] );
			if ( $roll <= $running ) {
				return $variant;
			}
		}

		return $variants[0];
	}

	private static function forced_variant_key() {
		if ( ! isset( $_GET['pbd_exp_variant'] ) ) {
			return '';
		}
		return sanitize_key( wp_unslash( $_GET['pbd_exp_variant'] ) );
	}

	private static function should_clear() {
		return isset( $_GET['pbd_exp_clear'] ) && '1' === (string) $_GET['pbd_exp_clear'];
	}
}
