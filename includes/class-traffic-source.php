<?php
defined( 'ABSPATH' ) || exit;

/**
 * First-touch traffic-source detection.
 *
 * Called once, at the moment a visitor's sticky assignment row is created, so
 * the source that brought them to the experiment is captured before any redirect
 * variant fires. The result is stored on the assignment row and every later event
 * the visitor records (including off-target conversions on a thank-you page) is
 * attributed back to it via a join. Attribution lives at the visitor grain, not
 * the event grain, so a funnel never splits across channels.
 *
 * Classification is GA-style and deliberately simple: enough to answer "did paid
 * behave differently from organic?" without a full analytics stack. Every rule
 * list is filterable so a site can extend it.
 */
final class PBD_Exp_Traffic_Source {

	/**
	 * Assignment columns that may be grouped on in a segment breakdown. Both the
	 * repo (SQL column allowlist) and the dashboard (selector) read this, so a
	 * column name can never arrive from raw user input into a query.
	 */
	const SEGMENT_COLS = array( 'channel', 'source', 'medium', 'device', 'referrer_host' );

	// Column widths, mirrored from class-schema.php, so stored values never overflow.
	const LEN_CHANNEL  = 20;
	const LEN_SOURCE   = 100;
	const LEN_MEDIUM   = 60;
	const LEN_CAMPAIGN = 191;
	const LEN_HOST     = 191;
	const LEN_DEVICE   = 12;

	/**
	 * Detect the current request's first-touch traffic source.
	 *
	 * @return array{channel:string,source:string,medium:string,campaign:string,referrer_host:string,device:string}
	 */
	public static function detect() {
		$utm_source   = self::get_param( 'utm_source' );
		$utm_medium   = self::get_param( 'utm_medium' );
		$utm_campaign = self::get_param( 'utm_campaign' );

		$has_paid_click = self::has_param( 'gclid' ) || self::has_param( 'gbraid' )
			|| self::has_param( 'wbraid' ) || self::has_param( 'msclkid' ) || self::has_param( 'fbclid' );

		$referrer_host = self::referrer_host();
		$device        = self::device();

		$channel = self::classify_channel( $utm_source, $utm_medium, $has_paid_click, $referrer_host );

		// Fill source/medium sensibly when utm tags are absent so the finer
		// breakdowns still read as something other than blank.
		$source = $utm_source;
		$medium = $utm_medium;
		if ( '' === $source ) {
			$source = self::default_source( $channel, $referrer_host, $has_paid_click );
		}
		if ( '' === $medium ) {
			$medium = self::default_medium( $channel );
		}

		$result = array(
			'channel'       => self::cap( $channel, self::LEN_CHANNEL ),
			'source'        => self::cap( $source, self::LEN_SOURCE ),
			'medium'        => self::cap( $medium, self::LEN_MEDIUM ),
			'campaign'      => self::cap( $utm_campaign, self::LEN_CAMPAIGN ),
			'referrer_host' => self::cap( $referrer_host, self::LEN_HOST ),
			'device'        => self::cap( $device, self::LEN_DEVICE ),
		);

		/**
		 * Final override of the whole detected traffic source. Mirrors the
		 * pbd_exp_bot_ua_regex convention. Return value must keep the same keys.
		 *
		 * @param array $result Detected source.
		 * @param array $context Raw inputs used for detection.
		 */
		return apply_filters(
			'pbd_exp_traffic_source',
			$result,
			array(
				'utm_source'     => $utm_source,
				'utm_medium'     => $utm_medium,
				'has_paid_click' => $has_paid_click,
				'referrer_host'  => $referrer_host,
			)
		);
	}

	private static function classify_channel( $utm_source, $utm_medium, $has_paid_click, $referrer_host ) {
		$medium = strtolower( $utm_medium );

		$paid_regex = apply_filters( 'pbd_exp_ts_paid_medium_regex', '/^(cpc|ppc|paid|paidsearch|paid-?social|display|cpm|retargeting|banner)/' );
		if ( $has_paid_click || ( '' !== $medium && preg_match( $paid_regex, $medium ) ) ) {
			return 'Paid';
		}

		$email_regex = apply_filters( 'pbd_exp_ts_email_medium_regex', '/^(e-?mail|newsletter)$/' );
		$email_sources = apply_filters( 'pbd_exp_ts_email_sources', array( 'mailchimp', 'klaviyo', 'activecampaign', 'campaignmonitor', 'sendgrid', 'newsletter', 'email' ) );
		if ( ( '' !== $medium && preg_match( $email_regex, $medium ) ) || in_array( strtolower( $utm_source ), array_map( 'strtolower', (array) $email_sources ), true ) ) {
			return 'Email';
		}

		$social_hosts = apply_filters( 'pbd_exp_ts_social_hosts', array(
			'facebook.com', 'm.facebook.com', 'l.facebook.com', 'lm.facebook.com', 'instagram.com',
			'l.instagram.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'lnkd.in',
			'youtube.com', 'm.youtube.com', 'pinterest.com', 'reddit.com', 'out.reddit.com',
			'tiktok.com', 'threads.net',
		) );
		if ( false !== strpos( $medium, 'social' ) || ( '' !== $referrer_host && in_array( $referrer_host, (array) $social_hosts, true ) ) ) {
			return 'Social';
		}

		$has_utm = '' !== $utm_source || '' !== $utm_medium;

		$search_hosts = apply_filters( 'pbd_exp_ts_search_hosts', array(
			'google.com', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'search.yahoo.com',
			'ecosia.org', 'baidu.com', 'yandex.com', 'yandex.ru', 'brave.com', 'startpage.com',
		) );
		if ( '' !== $referrer_host && self::host_matches( $referrer_host, (array) $search_hosts ) && ! $has_utm ) {
			return 'Organic';
		}

		if ( '' !== $referrer_host ) {
			return 'Referral';
		}

		return 'Direct';
	}

	private static function default_source( $channel, $referrer_host, $has_paid_click ) {
		switch ( $channel ) {
			case 'Direct':
				return '(direct)';
			case 'Organic':
			case 'Referral':
			case 'Social':
				return '' !== $referrer_host ? $referrer_host : '(unknown)';
			case 'Paid':
				return $has_paid_click ? 'google' : ( '' !== $referrer_host ? $referrer_host : '(unknown)' );
			default:
				return '' !== $referrer_host ? $referrer_host : '(unknown)';
		}
	}

	private static function default_medium( $channel ) {
		switch ( $channel ) {
			case 'Direct':
				return '(none)';
			case 'Organic':
				return 'organic';
			case 'Referral':
				return 'referral';
			case 'Social':
				return 'social';
			case 'Paid':
				return 'cpc';
			case 'Email':
				return 'email';
			default:
				return '(none)';
		}
	}

	private static function referrer_host() {
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( '' === $referrer ) {
			return '';
		}

		$host = wp_parse_url( $referrer, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}

		$host = strtolower( $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		// Same-host referrers are internal navigation, not a traffic source.
		$self = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		if ( 0 === strpos( $self, 'www.' ) ) {
			$self = substr( $self, 4 );
		}
		if ( '' !== $self && $host === $self ) {
			return '';
		}

		return $host;
	}

	private static function device() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return 'desktop';
		}

		// Tablet must be checked before mobile: many tablet UAs also match the
		// mobile token set, and Android tablets omit "mobile".
		if ( preg_match( '/ipad|tablet|playbook|silk|kindle|(android(?!.*mobile))/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/mobi|iphone|ipod|android|blackberry|iemobile|opera mini|windows phone/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Match a referrer host against an allowlist, tolerating country/sub variants
	 * (google.co.uk, news.google.com) by also matching on the registrable stem.
	 */
	private static function host_matches( $host, $hosts ) {
		if ( in_array( $host, $hosts, true ) ) {
			return true;
		}
		foreach ( $hosts as $known ) {
			$stem = preg_replace( '/\.[a-z.]+$/', '', $known ); // google.com -> google
			if ( $stem && ( $host === $stem || false !== strpos( $host, $stem . '.' ) || false !== strpos( $host, '.' . $stem . '.' ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function get_param( $key ) {
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	private static function has_param( $key ) {
		return isset( $_GET[ $key ] ) && '' !== (string) ( is_scalar( $_GET[ $key ] ) ? $_GET[ $key ] : '' );
	}

	private static function cap( $value, $len ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $len ) {
			return $value;
		}
		return substr( $value, 0, $len );
	}
}
