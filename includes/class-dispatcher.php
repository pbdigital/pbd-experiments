<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles the front-end lifecycle for an active experiment on the target page:
 *  - matches the request path against active experiments
 *  - picks a variant + sticky assignment
 *  - for redirect variants: emits a 302 (cookie set before Location header)
 *  - for template variants: lets template_include swap the template
 *  - fires the auto `experiment_viewed` event
 */
final class PBD_Exp_Dispatcher {

	private $current_experiment = null;
	private $current_assignment = null;

	public function hook() {
		add_action( 'template_redirect', array( $this, 'handle_target_request' ), 0 );
		add_filter( 'template_include', array( $this, 'template_include' ), 99 );
	}

	public function current_experiment() {
		return $this->current_experiment;
	}

	public function current_assignment() {
		return $this->current_assignment;
	}

	public function handle_target_request() {
		$path       = $this->request_path();
		$experiment = PBD_Exp_Repo::get_active_experiment_for_path( $path );
		if ( ! $experiment ) {
			return;
		}

		// Bots: skip entirely. No assignment, no dispatch, no events.
		if ( PBD_Exp_Exclusions::is_bot() ) {
			return;
		}

		$this->prevent_caching();

		// Logged-in admin in preview mode: resolve a non-persistent assignment
		// so the variant renders and the badge shows, but skip persistence and
		// the auto-fired experiment_viewed event so stats stay clean.
		if ( PBD_Exp_Exclusions::is_logged_in_excluded( $experiment ) ) {
			$preview = $this->preview_assignment( $experiment );
			if ( ! $preview ) {
				return;
			}
			$this->current_experiment = $experiment;
			$this->current_assignment = $preview;

			if ( 'redirect' === $preview['variant_type'] && ! empty( $preview['redirect_url'] ) ) {
				$target = $this->safe_redirect_target( $preview['redirect_url'] );
				if ( $target ) {
					wp_safe_redirect( $target, 302 );
					exit;
				}
			}
			return;
		}

		$assignment = PBD_Exp_Assignment::resolve( $experiment );
		if ( ! $assignment ) {
			return;
		}

		$this->current_experiment = $experiment;
		$this->current_assignment = $assignment;

		// Auto-record the exposure event. once=true keeps the dedupe sane.
		PBD_Exp_Events::record(
			$experiment['experiment_key'],
			'experiment_viewed',
			array( 'source' => 'pageview' ),
			true
		);

		// Redirect variants: emit 302 here. The visitor cookie was set inside
		// PBD_Exp_Assignment::resolve() BEFORE we touch Location, so the next
		// pageview lands with the cookie intact.
		if ( 'redirect' === $assignment['variant_type'] && ! empty( $assignment['redirect_url'] ) ) {
			$target = $this->safe_redirect_target( $assignment['redirect_url'] );
			if ( $target ) {
				wp_safe_redirect( $target, 302 );
				exit;
			}
		}
	}

	/**
	 * Ephemeral preview assignment for excluded logged-in users.
	 * Honours ?pbd_exp_variant=key forcing; otherwise picks the first variant
	 * deterministically (admins want stable preview, not random).
	 */
	private function preview_assignment( $experiment ) {
		$forced = isset( $_GET['pbd_exp_variant'] ) ? sanitize_key( wp_unslash( $_GET['pbd_exp_variant'] ) ) : '';
		$variant = $forced ? PBD_Exp_Repo::get_variant_by_key( (int) $experiment['id'], $forced ) : null;

		if ( ! $variant ) {
			$variants = PBD_Exp_Repo::get_variants( (int) $experiment['id'] );
			$variant  = ! empty( $variants ) ? $variants[0] : null;
		}

		if ( ! $variant ) {
			return null;
		}

		return array(
			'variant_id'    => (int) $variant['id'],
			'variant_key'   => $variant['variant_key'],
			'label'         => $variant['label'],
			'variant_type'  => $variant['variant_type'],
			'template_path' => $variant['template_path'],
			'redirect_url'  => $variant['redirect_url'],
		);
	}

	public function template_include( $template ) {
		if ( ! $this->current_experiment || ! $this->current_assignment ) {
			return $template;
		}

		if ( 'template' !== $this->current_assignment['variant_type'] ) {
			return $template;
		}

		if ( empty( $this->current_assignment['template_path'] ) ) {
			return $template;
		}

		$resolved = $this->resolve_template_path( $this->current_assignment['template_path'] );
		return $resolved ? $resolved : $template;
	}

	private function request_path() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$path = untrailingslashit( (string) $path );
		return $path ? $path : '/';
	}

	private function prevent_caching() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'Surrogate-Control: no-store', false );
		}
	}

	/**
	 * Same-site validation for redirect destinations. Returns the absolute URL
	 * to redirect to, or '' if the URL is off-site or malformed.
	 */
	private function safe_redirect_target( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$site_host = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';

		// Allow site-relative ("/foo/") paths.
		if ( '' !== $url && '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			return home_url( $url );
		}

		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return '';
		}

		if ( strtolower( $parts['host'] ) !== $site_host ) {
			return '';
		}

		return $url;
	}

	private function resolve_template_path( $template_path ) {
		if ( ! $template_path ) {
			return '';
		}

		// Absolute path inside WP — only trust it if it's under ABSPATH and exists.
		if ( 0 === strpos( $template_path, ABSPATH ) && file_exists( $template_path ) ) {
			return $template_path;
		}

		$child = trailingslashit( get_stylesheet_directory() ) . ltrim( $template_path, '/' );
		if ( file_exists( $child ) ) {
			return $child;
		}

		$parent = trailingslashit( get_template_directory() ) . ltrim( $template_path, '/' );
		if ( file_exists( $parent ) ) {
			return $parent;
		}

		$plugin = trailingslashit( PBD_EXP_PATH ) . ltrim( $template_path, '/' );
		if ( file_exists( $plugin ) ) {
			return $plugin;
		}

		return '';
	}
}
