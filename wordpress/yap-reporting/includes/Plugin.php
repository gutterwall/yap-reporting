<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/** Wires every component into WordPress. */
final class Plugin {

	public static function boot(): void {
		load_plugin_textdomain( 'yap-reporting', false, dirname( plugin_basename( YAPR_FILE ) ) . '/languages' );
		Installer::maybe_upgrade();

		add_filter( 'cron_schedules', array( Sync::class, 'cron_schedules' ) );
		Sync::register();
		Rest::register();
		Shortcodes::register();
		Export::register();

		if ( is_admin() ) {
			Admin::register();
		}
	}
}
