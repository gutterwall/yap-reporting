<?php
/**
 * Plugin Name:       YAP Reporting for WordPress
 * Plugin URI:        https://github.com/bmlt-enabled/yap
 * Description:       Private reporting dashboards and a compact Area Service dashboard for YAP phonelines, with BMLT service body integration. Reads data only through the secured YAP Reporting Webservice.
 * Version:           1.6.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            YAP Reporting contributors
 * License:           MIT
 * Text Domain:       yap-reporting
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'YAPR_VERSION', '1.6.0' );
// Compatibility with the YAP Reporting Webservice (see COMPATIBILITY.md).
define( 'YAPR_REQUIRED_API_VERSION', 'v1' );
define( 'YAPR_MIN_SERVICE_VERSION', '1.6.0' );
define( 'YAPR_DB_VERSION', '1' );
define( 'YAPR_FILE', __FILE__ );
define( 'YAPR_DIR', plugin_dir_path( __FILE__ ) );
define( 'YAPR_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, 'YAPR\\' ) ) {
			return;
		}
		$file = YAPR_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'YAPR\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'YAPR\\Installer', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'YAPR\\Plugin', 'boot' ) );
