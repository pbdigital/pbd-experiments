<?php
defined( 'ABSPATH' ) || exit;

final class PBD_Exp_Visitor {

	const COOKIE = 'pbd_exp_vid';
	const COOKIE_DAYS = 365;

	public static function get_id() {
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$id = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( preg_match( '/^[a-zA-Z0-9-]{32,64}$/', $id ) ) {
				return $id;
			}
		}

		$id = str_replace( '-', '', wp_generate_uuid4() );
		$_COOKIE[ self::COOKIE ] = $id;

		self::set_cookie( $id, self::COOKIE_DAYS );

		return $id;
	}

	public static function set_cookie( $id, $days ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE, $id, self::cookie_options( time() + (int) $days * DAY_IN_SECONDS ) );
	}

	private static function cookie_options( $expires ) {
		return array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		);
	}
}
