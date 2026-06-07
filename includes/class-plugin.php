<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton. Wires up the front-end dispatcher, REST, shortcode, and admin.
 */
final class PBD_Exp_Plugin {

	private static $instance = null;

	/** @var PBD_Exp_Dispatcher */
	private $dispatcher;

	/** @var PBD_Exp_Frontend */
	private $frontend;

	/** @var PBD_Exp_Rest */
	private $rest;

	/** @var PBD_Exp_Shortcode */
	private $shortcode;

	/** @var PBD_Exp_Admin */
	private $admin;

	/** @var PBD_Exp_Updater */
	private $updater;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->dispatcher = new PBD_Exp_Dispatcher();
		$this->frontend   = new PBD_Exp_Frontend( $this->dispatcher );
		$this->rest       = new PBD_Exp_Rest();
		$this->shortcode  = new PBD_Exp_Shortcode();
		$this->admin      = new PBD_Exp_Admin();
		$this->updater    = new PBD_Exp_Updater();

		$this->dispatcher->hook();
		$this->frontend->hook();
		$this->rest->hook();
		$this->shortcode->hook();
		$this->admin->hook();
		$this->updater->hook();

		add_action( 'admin_init', array( 'PBD_Exp_Schema', 'maybe_upgrade' ) );
	}

	public function dispatcher() {
		return $this->dispatcher;
	}
}
