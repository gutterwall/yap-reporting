<?php

declare(strict_types=1);

namespace YAPR\Tests;

use YAPR\Access;
use YAPR\ApiClient;
use YAPR\Areas;
use YAPR\BmltClient;
use YAPR\Cache;
use YAPR\Installer;
use YAPR\Settings;
use YAPR\UrlGuard;

/**
 * A site set up the way the README's Setup page describes: credential and API URL saved, service bodies loaded
 * from BMLT, the Region and three of its Areas selected, roles granted.
 *
 * Users: administrator; phoneline administrator (role grant: every Area); Area reporter (role grant: Area 1001);
 * Area reporter with a personal grant (Area 1002); editor and subscriber (no reporting permissions).
 */
abstract class TestCase extends \WP_UnitTestCase
{
    public const MANAGED = [1000, 1001, 1002, 1003];

    protected static int $admin;
    protected static int $phoneline;
    protected static int $reporter;
    protected static int $reporter1002;
    protected static int $editor;
    protected static int $subscriber;

    public static function wpSetUpBeforeClass(\WP_UnitTest_Factory $factory): void
    {
        self::$admin = $factory->user->create(['role' => 'administrator']);
        self::$phoneline = $factory->user->create(['role' => Installer::ROLE_PHONELINE_ADMIN]);
        self::$reporter = $factory->user->create(['role' => Installer::ROLE_AREA_REPORTER]);
        self::$reporter1002 = $factory->user->create(['role' => Installer::ROLE_AREA_REPORTER]);
        self::$editor = $factory->user->create(['role' => 'editor']);
        self::$subscriber = $factory->user->create(['role' => 'subscriber']);
        update_user_meta(self::$reporter1002, Access::USER_META, [1002]);
    }

    public function set_up(): void
    {
        parent::set_up();
        // The test host names resolve to a public address (the requests themselves never leave the process).
        UrlGuard::$resolver = static fn (string $host): array => ['93.184.215.14'];
        FakeService::$override = null;
        Settings::update([
            'api_url' => FakeService::API,
            'bmlt_root' => FakeService::BMLT,
            'timezone' => 'America/New_York',
            'default_period' => '30d',
            'include_unassigned' => 1,
            'show_volunteer_names' => 1,
            'exports_enabled' => 1,
            'public_areas' => [],
            'role_areas' => [Installer::ROLE_PHONELINE_ADMIN => 'all', Installer::ROLE_AREA_REPORTER => [1001]],
            'area_labels' => [],
            'default_area' => 1001,
        ]);
        Settings::set_token(FakeService::$token);
        $this->assertIsInt(BmltClient::refresh());
        Areas::set_managed(self::MANAGED);
        $this->assertTrue(ApiClient::test_connection()['ok']);
        Cache::clear();
        wp_set_current_user(0);
    }

    public function tear_down(): void
    {
        FakeService::$override = null;
        UrlGuard::$resolver = null;
        Areas::flush();
        parent::tear_down();
    }

    /** @param array<string, mixed> $params */
    protected function rest(string $route, array $params = [], int $user = 0): \WP_REST_Response
    {
        wp_set_current_user($user);
        $request = new \WP_REST_Request('GET', '/yapr/v1' . $route);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        return rest_get_server()->dispatch($request);
    }

    /**
     * Assert that the service's figures equal the scenario's.
     *
     * @param array<string, mixed> $expected
     * @param array<string, array<string, mixed>> $metrics
     */
    protected function assertFigures(array $expected, array $metrics, string $label = ''): void
    {
        $got = [];
        foreach (array_keys($expected) as $k) {
            $got[$k] = isset($metrics[$k]) && array_key_exists('value', $metrics[$k]) ? $metrics[$k]['value'] : '(missing)';
        }
        $this->assertSame($expected, $got, $label);
    }

    /** Local dates, inclusive, for the last $days days ending today. */
    protected static function lastDays(int $days): array
    {
        $tz = new \DateTimeZone('America/New_York');
        $today = new \DateTimeImmutable('today', $tz);
        return [$today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), $today->format('Y-m-d')];
    }
}
