<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-update from GitHub Releases.
 *
 * Bundles the YahnisElsts Plugin Update Checker library and points it at the
 * public pbdigital/pbd-experiments repo. PUC hooks WordPress's plugin-update
 * transient, so a newer GitHub Release shows up as a normal "Update available"
 * notice on the Plugins screen with one-click update. No update server, no
 * separate plugin on the client site.
 *
 * The checker uses release assets: it downloads the zip that the repo's
 * release GitHub Action attaches to each Release, not GitHub's auto-generated
 * source archive (which would carry dev docs and a version-suffixed folder
 * name that breaks the in-place swap).
 */
class PBD_Exp_Updater {

	const REPO_URL = 'https://github.com/pbdigital/pbd-experiments/';
	const SLUG     = 'pbd-experiments';

	public function hook() {
		require_once PBD_EXP_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			PBD_EXP_FILE,
			self::SLUG
		);

		$checker->setBranch( 'main' );
		$checker->getVcsApi()->enableReleaseAssets();
	}
}
