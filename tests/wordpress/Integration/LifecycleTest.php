<?php

declare(strict_types=1);

namespace YAPR\Tests\Integration;

use YAPR\Access;
use YAPR\Installer;
use YAPR\Settings;
use YAPR\Sync;
use YAPR\Tests\TestCase;

/** Activation, upgrade, deactivation and uninstall. */
final class LifecycleTest extends TestCase
{
    public function tear_down(): void
    {
        wp_roles()->for_site();
        Installer::install_roles();
        parent::tear_down();
    }

    public function test_activation_creates_tables_roles_capabilities_and_jobs(): void
    {
        global $wpdb;
        foreach (['areas', 'aggregate_cache', 'sync_state'] as $t) {
            $this->assertSame($wpdb->prefix . 'yapr_' . $t, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'yapr_' . $t)));
        }
        $admin = get_role('administrator');
        foreach (Access::ALL_CAPS as $cap) {
            $this->assertTrue($admin->has_cap($cap), $cap);
        }
        $this->assertTrue(get_role(Installer::ROLE_PHONELINE_ADMIN)->has_cap(Access::CAP_VIEW_CALLS));
        $this->assertFalse(get_role(Installer::ROLE_AREA_REPORTER)->has_cap(Access::CAP_VIEW_CALLS));
        $this->assertFalse(get_role(Installer::ROLE_AREA_REPORTER)->has_cap(Access::CAP_MANAGE_SETTINGS));
        Installer::activate();
        foreach (Sync::JOBS as $job) {
            $this->assertNotFalse(wp_next_scheduled('yapr_sync_' . $job));
        }
        $this->assertNotFalse(wp_next_scheduled('yapr_cache_prune'));
        $this->assertSame(YAPR_DB_VERSION, get_option('yapr_db_version'));
    }

    public function test_upgrade_runs_on_init_and_keeps_settings(): void
    {
        Settings::update(['public_areas' => [1001]]);
        update_option('yapr_db_version', '0');
        do_action('init');
        $this->assertSame(YAPR_DB_VERSION, get_option('yapr_db_version'));
        $this->assertSame([1001], Settings::get('public_areas'));
        $this->assertArrayHasKey('include_unassigned', Settings::all(), 'new settings get defaults');
    }

    public function test_deactivation_removes_jobs_only(): void
    {
        Installer::activate();
        Installer::deactivate();
        foreach (Sync::JOBS as $job) {
            $this->assertFalse(wp_next_scheduled('yapr_sync_' . $job));
        }
        $this->assertNotSame('', Settings::api_token(), 'credential kept');
    }

    /** @return string[] queries run by uninstall.php */
    private function uninstall(): array
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'yap-reporting/yap-reporting.php');
        }
        $queries = [];
        $capture = static function (string $q) use (&$queries): string {
            $queries[] = $q;
            return $q;
        };
        add_filter('query', $capture, 1);
        include dirname(__DIR__, 3) . '/wordpress/yap-reporting/uninstall.php';
        remove_filter('query', $capture, 1);
        return $queries;
    }

    public function test_uninstall_removes_credential_caches_jobs_and_by_default_everything(): void
    {
        set_transient('yapr_lock_abc', 1, 60);
        Installer::activate();
        $queries = implode("\n", $this->uninstall());
        $this->assertFalse(get_option('yapr_api_token'));
        $this->assertFalse(get_option('yapr_settings'));
        $this->assertSame('0', $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '%transient%yapr%'"));
        $this->assertFalse(wp_next_scheduled('yapr_sync_recent'));
        foreach (['aggregate_cache', 'sync_state', 'areas'] as $t) {
            $this->assertStringContainsString('DROP TABLE IF EXISTS ' . $GLOBALS['wpdb']->prefix . 'yapr_' . $t, str_replace('TEMPORARY ', '', $queries));
        }
        $this->assertSame('', get_user_meta(self::$reporter1002, Access::USER_META, true));
        wp_roles()->for_site();
        $this->assertNull(get_role(Installer::ROLE_PHONELINE_ADMIN));
        $this->assertFalse(get_role('administrator')->has_cap(Access::CAP_VIEW_REPORTS));
    }

    public function test_uninstall_can_keep_settings_but_never_the_credential(): void
    {
        Settings::update(['keep_data_on_uninstall' => 1]);
        $queries = implode("\n", $this->uninstall());
        $this->assertFalse(get_option('yapr_api_token'));
        $this->assertSame([1001], Settings::get('role_areas')[Installer::ROLE_AREA_REPORTER]);
        $this->assertStringNotContainsString('yapr_areas', $queries, 'Area selection kept');
        $this->assertSame([1002], get_user_meta(self::$reporter1002, Access::USER_META, true));
        $this->assertFalse(get_option('yapr_db_version'), 'tables are recreated on reinstall');
    }
}
