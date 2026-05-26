<?php
defined( 'ABSPATH' ) || exit;

final class PBD_Exp_Exclusions {

	const BOT_REGEX_DEFAULT = '/(bot|crawler|spider|crawling|slurp|mediapartners|facebookexternalhit|embedly|quora link preview|outbrain|pinterest|vkshare|w3c_validator|whatsapp|telegrambot|preview|fetch|monitor|wget|curl|python-requests|libwww|httpclient|scrapy|headlesschrome|phantomjs|lighthouse|pagespeed)/i';

	/**
	 * Returns true when the current request should be excluded from assignment.
	 * Used by the dispatcher to skip variant assignment and event recording.
	 *
	 * @param array|null $experiment Optional experiment row. Allows per-experiment override
	 *                               of the logged-in exclusion via include_logged_in flag.
	 */
	public static function should_exclude( $experiment = null ) {
		if ( self::is_bot() ) {
			return true;
		}

		if ( self::is_logged_in_excluded( $experiment ) ) {
			return true;
		}

		return false;
	}

	public static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua ) {
			return false;
		}

		$pattern = apply_filters( 'pbd_exp_bot_ua_regex', self::BOT_REGEX_DEFAULT );
		return (bool) preg_match( $pattern, $ua );
	}

	public static function is_logged_in_excluded( $experiment = null ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$include_logged_in = $experiment && ! empty( $experiment['include_logged_in'] );

		return ! $include_logged_in;
	}
}
