<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/** Wires every component into WordPress. */
final class Plugin {

	public static function boot(): void {
		// Translations, and upgrades (which name the plugin's roles), wait for init: WordPress 6.7+ warns about
		// translations loaded earlier.
		add_action( 'init', array( self::class, 'init' ), 1 );

		add_filter( 'cron_schedules', array( Sync::class, 'cron_schedules' ) );
		Sync::register();
		Rest::register();
		Shortcodes::register();
		Export::register();

		if ( is_admin() ) {
			Admin::register();
		}
	}

	public static function init(): void {
		load_plugin_textdomain( 'yap-reporting', false, dirname( plugin_basename( YAPR_FILE ) ) . '/languages' );
		Installer::maybe_upgrade();
	}
}
