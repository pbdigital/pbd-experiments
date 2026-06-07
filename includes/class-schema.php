<?php
defined( 'ABSPATH' ) || exit;

final class PBD_Exp_Schema {

	const TABLE_PREFIX = 'pbd_experiments_';
	const OPTION_DB_VERSION = 'pbd_experiments_db_version';
	const DB_VERSION = '1.2.0';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_PREFIX . $name;
	}

	public static function install() {
		self::create_tables();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$experiments     = self::table( 'experiments' );
		$variants        = self::table( 'variants' );
		$assignments     = self::table( 'assignments' );
		$events          = self::table( 'events' );
		$metrics         = self::table( 'metrics' );
		$snapshots       = self::table( 'snapshots' );

		dbDelta(
			"CREATE TABLE {$experiments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				experiment_key varchar(100) NOT NULL,
				name varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				target_path varchar(191) NOT NULL DEFAULT '/',
				cookie_days int(11) NOT NULL DEFAULT 90,
				include_logged_in tinyint(1) NOT NULL DEFAULT 0,
				winning_variant_id bigint(20) unsigned DEFAULT NULL,
				notes text NULL,
				started_at datetime NULL,
				concluded_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY experiment_key (experiment_key),
				KEY status_target (status,target_path)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$variants} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				experiment_id bigint(20) unsigned NOT NULL,
				variant_key varchar(100) NOT NULL,
				label varchar(191) NOT NULL,
				weight int(11) NOT NULL DEFAULT 50,
				variant_type varchar(20) NOT NULL DEFAULT 'template',
				template_path varchar(255) NOT NULL DEFAULT '',
				redirect_url varchar(255) NOT NULL DEFAULT '',
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY experiment_variant (experiment_id,variant_key),
				KEY experiment_id (experiment_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$assignments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				visitor_id varchar(64) NOT NULL,
				experiment_id bigint(20) unsigned NOT NULL,
				variant_id bigint(20) unsigned NOT NULL,
				assigned_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY visitor_experiment (visitor_id,experiment_id),
				KEY experiment_variant (experiment_id,variant_id),
				KEY visitor_id (visitor_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				visitor_id varchar(64) NOT NULL,
				experiment_id bigint(20) unsigned NOT NULL,
				variant_id bigint(20) unsigned DEFAULT NULL,
				event_name varchar(100) NOT NULL,
				url text NOT NULL,
				referrer text NOT NULL,
				metadata longtext NULL,
				occurred_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY experiment_event (experiment_id,event_name),
				KEY visitor_experiment_event (visitor_id,experiment_id,event_name),
				KEY occurred_at (occurred_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$metrics} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				experiment_id bigint(20) unsigned NOT NULL,
				metric_key varchar(100) NOT NULL,
				name varchar(191) NOT NULL,
				event_name varchar(100) NOT NULL,
				trigger_type varchar(20) NOT NULL DEFAULT 'page',
				selector varchar(255) NOT NULL DEFAULT '',
				form_type varchar(40) NOT NULL DEFAULT '',
				active tinyint(1) NOT NULL DEFAULT 1,
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY experiment_metric (experiment_id,metric_key),
				KEY experiment_id (experiment_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$snapshots} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				experiment_id bigint(20) unsigned NOT NULL,
				snapshot longtext NOT NULL,
				frozen_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY experiment_id (experiment_id)
			) {$charset_collate};"
		);
	}
}
