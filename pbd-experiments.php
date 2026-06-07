<?php
/**
 * Plugin Name: PBD Experiments
 * Plugin URI: https://github.com/pbdigital/pbd-experiments
 * Description: Agency-grade WordPress split-testing. Assigns variants, dispatches templates or redirects, records events, reports results.
 * Version: 1.1.0
 * Author: PB Digital
 * Author URI: https://pbdigital.com.au
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: pbd-experiments
 */

defined( 'ABSPATH' ) || exit;

define( 'PBD_EXP_VERSION', '1.1.0' );
define( 'PBD_EXP_FILE', __FILE__ );
define( 'PBD_EXP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PBD_EXP_URL', plugin_dir_url( __FILE__ ) );
define( 'PBD_EXP_BASENAME', plugin_basename( __FILE__ ) );

require_once PBD_EXP_PATH . 'includes/class-schema.php';
require_once PBD_EXP_PATH . 'includes/class-repo.php';
require_once PBD_EXP_PATH . 'includes/class-visitor.php';
require_once PBD_EXP_PATH . 'includes/class-exclusions.php';
require_once PBD_EXP_PATH . 'includes/class-assignment.php';
require_once PBD_EXP_PATH . 'includes/class-events.php';
require_once PBD_EXP_PATH . 'includes/class-dispatcher.php';
require_once PBD_EXP_PATH . 'includes/class-frontend.php';
require_once PBD_EXP_PATH . 'includes/class-rest.php';
require_once PBD_EXP_PATH . 'includes/class-shortcode.php';
require_once PBD_EXP_PATH . 'includes/admin/class-admin.php';
require_once PBD_EXP_PATH . 'includes/admin/class-edit.php';
require_once PBD_EXP_PATH . 'includes/admin/class-dashboard.php';
require_once PBD_EXP_PATH . 'includes/admin/class-archive.php';
require_once PBD_EXP_PATH . 'includes/class-updater.php';
require_once PBD_EXP_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'PBD_Exp_Schema', 'install' ) );

add_action( 'plugins_loaded', array( 'PBD_Exp_Plugin', 'instance' ), 5 );
