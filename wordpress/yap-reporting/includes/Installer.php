<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/** Activation, upgrades, database tables, roles and capabilities. */
final class Installer {

	public const ROLE_PHONELINE_ADMIN = 'yapr_phoneline_admin';
	public const ROLE_AREA_REPORTER   = 'yapr_area_reporter';

	public static function activate(): void {
		self::create_tables();
		self::install_roles();
		Settings::ensure_defaults();
		Sync::schedule();
		update_option( 'yapr_db_version', YAPR_DB_VERSION, false );
	}

	public static function deactivate(): void {
		Sync::unschedule();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'yapr_db_version' ) !== YAPR_DB_VERSION ) {
			self::activate();
		}
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'yapr_' . $name;
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$areas = self::table( 'areas' );
		$cache = self::table( 'aggregate_cache' );
		$state = self::table( 'sync_state' );

		dbDelta(
			"CREATE TABLE {$areas} (
				id bigint(20) unsigned NOT NULL,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(255) NOT NULL DEFAULT '',
				type varchar(16) NOT NULL DEFAULT '',
				managed tinyint(1) NOT NULL DEFAULT 0,
				present_in_bmlt tinyint(1) NOT NULL DEFAULT 1,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY parent_id (parent_id),
				KEY managed (managed)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$cache} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				cache_key char(64) NOT NULL,
				visibility varchar(10) NOT NULL DEFAULT 'private',
				endpoint varchar(40) NOT NULL,
				areas_key varchar(255) NOT NULL DEFAULT '',
				period varchar(40) NOT NULL DEFAULT '',
				payload longtext NOT NULL,
				fetched_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY cache_key (cache_key),
				KEY endpoint (endpoint),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$state} (
				job varchar(40) NOT NULL,
				last_attempt datetime NULL DEFAULT NULL,
				last_success datetime NULL DEFAULT NULL,
				last_error text NULL,
				failures int(11) NOT NULL DEFAULT 0,
				next_attempt datetime NULL DEFAULT NULL,
				duration_ms int(11) NOT NULL DEFAULT 0,
				items int(11) NOT NULL DEFAULT 0,
				failed_areas text NULL,
				PRIMARY KEY  (job)
			) {$charset};"
		);
	}

	public static function install_roles(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( Access::ALL_CAPS as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$phoneline = array(
			'read'                       => true,
			Access::CAP_VIEW_REPORTS     => true,
			Access::CAP_VIEW_CALLS       => true,
			Access::CAP_VIEW_VOLUNTEERS  => true,
			Access::CAP_EXPORT           => true,
		);
		$reporter  = array(
			'read'                   => true,
			Access::CAP_VIEW_REPORTS => true,
			Access::CAP_EXPORT       => true,
		);

		if ( ! get_role( self::ROLE_PHONELINE_ADMIN ) ) {
			add_role( self::ROLE_PHONELINE_ADMIN, __( 'Phoneline Administrator', 'yap-reporting' ), $phoneline );
		}
		if ( ! get_role( self::ROLE_AREA_REPORTER ) ) {
			add_role( self::ROLE_AREA_REPORTER, __( 'Area Reporter', 'yap-reporting' ), $reporter );
		}
	}

	public static function remove_roles(): void {
		remove_role( self::ROLE_PHONELINE_ADMIN );
		remove_role( self::ROLE_AREA_REPORTER );
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( Access::ALL_CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
