<?php

declare(strict_types=1);

namespace YAPR\Tests\Integration;

use YAPR\Access;
use YAPR\Admin;
use YAPR\Areas;
use YAPR\Cache;
use YAPR\Installer;
use YAPR\Reports;
use YAPR\Settings;
use YAPR\Tests\FakeService;
use YAPR\Tests\TestCase;

final class AdminRedirect extends \RuntimeException
{
}

/** Every admin screen renders, and every form handler checks permission and nonce and stores only clean values. */
final class AdminTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('wp_redirect', static function (string $location): string {
            throw new AdminRedirect($location);
        });
        set_current_screen('dashboard');
    }

    public function tear_down(): void
    {
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];
        set_current_screen('front');
        parent::tear_down();
    }

    /** Run an admin-post handler as $user with a valid nonce; returns the notice it left. */
    private function post(string $action, array $fields, int $user, bool $nonce = true): array
    {
        wp_set_current_user($user);
        $_POST = $fields + ['action' => 'yapr_' . $action];
        if ($nonce) {
            $_POST['_wpnonce'] = wp_create_nonce('yapr_' . $action);
        }
        $_REQUEST = $_POST;
        try {
            Admin::{'handle_' . $action}();
            $this->fail('handler did not redirect');
        } catch (AdminRedirect $e) {
            $this->assertStringContainsString('admin.php?page=yapr', $e->getMessage());
        }
        return (array) get_transient('yapr_notice_' . $user);
    }

    public function test_every_screen_renders_for_an_administrator(): void
    {
        wp_set_current_user(self::$admin);
        Admin::settings_api();
        foreach (['page_dashboard', 'page_reports', 'page_calls', 'page_volunteers', 'page_area_dashboards', 'page_settings', 'page_bmlt', 'page_cache', 'page_access', 'page_diagnostics', 'page_setup'] as $page) {
            ob_start();
            Admin::$page();
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('<div class="wrap yapr-wrap">', $html, $page);
            $this->assertStringNotContainsString(FakeService::$token, $html, $page);
            $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error)<\/b>:/', $html, $page);
        }
    }

    public function test_handlers_refuse_without_permission_or_nonce(): void
    {
        foreach (['access_save' => self::$phoneline, 'bmlt_save' => self::$phoneline, 'sync' => self::$reporter, 'clear_cache' => self::$editor] as $action => $user) {
            try {
                $this->post($action, [], $user);
                $this->fail($action);
            } catch (\WPDieException $e) {
                $this->assertStringContainsString('permission', $e->getMessage(), $action);
            }
        }
        try {
            $this->post('clear_cache', [], self::$admin, false);
            $this->fail('accepted without a nonce');
        } catch (\WPDieException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_access_grants_are_limited_to_selected_areas_and_real_roles(): void
    {
        $notice = $this->post('access_save', [
            'role' => [
                Installer::ROLE_AREA_REPORTER => ['mode' => 'some', 'areas' => ['1002', '1004', '99']],
                Installer::ROLE_PHONELINE_ADMIN => ['mode' => 'all'],
                'administrator' => ['mode' => 'some', 'areas' => ['1001']],
                'no_such_role' => ['mode' => 'all'],
            ],
            'user' => [
                self::$reporter1002 => ['mode' => 'inherit'],
                self::$editor => ['mode' => 'some', 'areas' => ['1003']],
                999999 => ['mode' => 'all'],
            ],
        ], self::$admin);
        $this->assertSame('success', $notice[0]);
        $this->assertSame([Installer::ROLE_AREA_REPORTER => [1002], Installer::ROLE_PHONELINE_ADMIN => 'all'], Settings::get('role_areas'));
        $this->assertSame('', get_user_meta(self::$reporter1002, Access::USER_META, true), 'back to the role grant');
        $this->assertSame([1003], get_user_meta(self::$editor, Access::USER_META, true));
        $this->assertSame([1002], Access::allowed_areas(self::$reporter));
        $this->assertSame([], Access::allowed_areas(self::$editor), 'a grant without the permission shows nothing');
    }

    public function test_settings_are_sanitised(): void
    {
        wp_set_current_user(self::$admin);
        $_POST = ['option_page' => 'yapr_general', 'yapr_new_token' => 'not-a-credential'];
        $out = Admin::sanitize_settings([
            'api_url' => ' https://yap.example.org/reporting-api/api/v1/ ', 'timezone' => 'Mars/Olympus', 'default_period' => 'forever',
            'interval_recent' => '1', 'interval_history' => '99999999', 'stale_factor' => '-3', 'sync_periods' => ['7d', 'bogus', '30d'],
            'exports_enabled' => '', 'chart_style' => '<script>',
        ]);
        $this->assertSame(FakeService::API, $out['api_url']);
        $this->assertSame('America/New_York', $out['timezone']);
        $this->assertSame('30d', $out['default_period']);
        $this->assertSame(5, $out['interval_recent']);
        $this->assertSame(10080, $out['interval_history']);
        $this->assertSame(2, $out['stale_factor']);
        $this->assertSame(['7d', '30d'], $out['sync_periods']);
        $this->assertSame(0, $out['exports_enabled']);
        $this->assertNotSame('<script>', $out['chart_style']);
        $this->assertSame(FakeService::$token, Settings::api_token(), 'a malformed credential is not saved');
        $this->assertNotEmpty(get_settings_errors('yapr'));

        $_POST['yapr_new_token'] = $fresh = FakeService::credential(true, [], ['summary'], true);
        Admin::sanitize_settings(['api_url' => 'http://insecure.example.org/reporting-api']);
        $this->assertSame($fresh, Settings::api_token());
        $this->assertSame(FakeService::API, Settings::api_url(), 'http:// is refused');

        $_POST = [];
        $this->assertSame(['anything' => 1], Admin::sanitize_settings(['anything' => 1]), 'programmatic updates pass through');
    }

    public function test_bmlt_selection_labels_and_adopted_ids(): void
    {
        $this->post('bmlt_areas', ['managed' => ['1001', '1004', 'x']], self::$admin);
        $this->assertSame([1001, 1004], Areas::managed_ids());
        $this->post('bmlt_areas', ['labels_only' => '1', 'labels' => ['1001' => ' Riverside <b>NA</b> ', '0' => 'x'], 'adopt' => ['4242']], self::$admin);
        $this->assertSame([1001 => 'Riverside NA'], Settings::get('area_labels'));
        $this->assertTrue(Areas::exists(4242), 'an id YAP uses that BMLT does not list');
        $this->assertTrue(Areas::is_managed(4242));

        $notice = $this->post('bmlt_save', ['bmlt_root' => 'https://10.0.0.5/main_server'], self::$admin);
        $this->assertSame('error', $notice[0]);
        $notice = $this->post('bmlt_save', ['bmlt_root' => FakeService::BMLT . '/', 'do' => 'refresh'], self::$admin);
        $this->assertSame(['success', 'Loaded 7 service bodies.'], $notice);
    }

    public function test_publishing_clears_old_public_copies(): void
    {
        Settings::update(['public_areas' => [1001]]);
        Reports::aggregate('area-dashboard', [1001], ['period' => '30d', 'compare' => 'true'], 'public');
        Reports::aggregate('area-dashboard', [1001], ['period' => '30d', 'compare' => 'true'], 'private');
        $this->assertSame(1, Cache::stats()['public']);
        $this->post('public_save', ['public_areas' => ['1001', '1004', '2001'], 'public_metrics' => ['calls_answered', 'volunteer_names', 'answer_rate']], self::$admin);
        $this->assertSame([1001], Settings::get('public_areas'), 'only selected Areas');
        $this->assertSame(['calls_answered', 'answer_rate'], Settings::get('public_metrics'), 'only dashboard figures');
        $this->assertSame(0, Cache::stats()['public']);
        $this->assertSame(1, Cache::stats()['records'], 'private copies stay');
    }

    public function test_maintenance_buttons(): void
    {
        $this->assertSame('success', $this->post('test_connection', ['back' => 'yapr-settings'], self::$admin)[0]);
        $this->assertSame('success', $this->post('sync', ['job' => 'bmlt'], self::$admin)[0]);
        $notice = $this->post('rebuild', [], self::$admin);
        $this->assertSame('success', $notice[0]);
        $this->assertGreaterThan(10, Cache::stats()['records']);
        $this->assertStringContainsString('Removed', $this->post('clear_cache', [], self::$admin)[1]);
        $this->assertSame(0, Cache::stats()['records']);
        $this->post('setup_done', [], self::$admin);
        $this->assertSame(1, Settings::get('setup_complete'));
    }
}
