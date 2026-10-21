<?php
/**
 * WordPress integration tests for the plugin.
 *
 *   WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist
 *
 * The plugin talks to the real webservice code (in-process, see FakeService.php), which reads a fake YAP database
 * created on the WordPress test database server. That needs an account allowed to create databases and users:
 * YAPR_TEST_DB_ADMIN_USER (default root) and YAPR_TEST_DB_ADMIN_PASS (default empty).
 */

$yapr_root  = dirname( __DIR__, 2 );
$yapr_tests = getenv( 'WP_TESTS_DIR' );
if ( ! $yapr_tests || ! file_exists( $yapr_tests . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to a WordPress test library (wp scaffold plugin-tests / install-wp-tests.sh).\n" );
	exit( 1 );
}

require_once $yapr_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $yapr_root . '/webservice/reporting-api/src/autoload.php';
require_once $yapr_root . '/fake-yap/src/YapWriter.php';
require_once $yapr_root . '/fake-yap/src/Scenario.php';
require_once $yapr_root . '/fake-yap/src/Installer.php';
require_once __DIR__ . '/FakeService.php';
require_once $yapr_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $yapr_root ) {
		require $yapr_root . '/wordpress/yap-reporting/yap-reporting.php';
	}
);
tests_add_filter(
	'init',
	static function () {
		YAPR\Installer::activate();
		YAPR\Tests\FakeService::install();
	},
	20
);

require $yapr_tests . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
