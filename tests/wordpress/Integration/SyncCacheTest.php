<?php

declare(strict_types=1);

namespace YAPR\Tests\Integration;

use YAPR\Cache;
use YAPR\Reports;
use YAPR\Settings;
use YAPR\Sync;
use YAPR\Tests\FakeService;
use YAPR\Tests\TestCase;

/** Aggregate cache, background synchronization, retries and locking. */
final class SyncCacheTest extends TestCase
{
    public function test_dashboards_are_served_from_the_cache_until_it_expires(): void
    {
        $params = ['period' => '30d', 'timezone' => 'America/New_York'];
        $before = count(FakeService::$log);
        $first = Reports::aggregate('summary', [1001], $params);
        $this->assertSame('api', $first['source']);
        $second = Reports::aggregate('summary', [1001], $params);
        $this->assertSame('cache', $second['source']);
        $this->assertSame($first['data'], $second['data']);
        $this->assertSame(1, FakeService::apiRequests($before), 'one request for two views');

        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->prefix}yapr_aggregate_cache SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)");
        $this->assertSame('api', Reports::aggregate('summary', [1001], $params)['source']);
    }

    public function test_cache_keys_separate_visibility_areas_and_parameters(): void
    {
        $base = Cache::key('private', 'summary', [1001, 1002], ['period' => '30d']);
        $this->assertSame($base, Cache::key('private', 'summary', [1002, 1001], ['period' => '30d']), 'order does not matter');
        $this->assertNotSame($base, Cache::key('public', 'summary', [1001, 1002], ['period' => '30d']));
        $this->assertNotSame($base, Cache::key('private', 'summary', [1001], ['period' => '30d']));
        $this->assertNotSame($base, Cache::key('private', 'summary', [1001, 1002], ['period' => '7d']));
        $this->assertNotSame($base, Cache::key('private', 'summary', [1001, 1002], ['period' => '30d', 'include_unassigned' => 'true']));
    }

    public function test_recent_sync_fills_the_cache_that_dashboards_read(): void
    {
        Settings::update(['sync_periods' => ['7d', '30d'], 'public_areas' => [1002]]);
        $result = Sync::run('recent', true);
        $this->assertTrue($result['ok'], $result['message']);
        // Per managed Area: one dashboard per period, a summary and a trend; then the combined summary and trend;
        // then the published Area's public dashboards.
        $this->assertSame(count(self::MANAGED) * 4 + 2 + 2, $result['items']);
        $state = Sync::state('recent');
        $this->assertSame('0', (string) $state['failures']);
        $this->assertNotEmpty($state['last_success']);

        $before = count(FakeService::$log);
        wp_set_current_user(self::$phoneline);
        $r = $this->rest('/report', ['view' => 'summary'], self::$phoneline);
        $this->assertSame(200, $r->get_status());
        $this->assertSame('cache', $r->get_data()['source'], 'All my Areas (with region-wide activity) was synchronized');
        $this->assertSame(0, FakeService::apiRequests($before));
        [$from, $to] = self::lastDays(30);
        $this->assertFigures(FakeService::expected(self::MANAGED, true, $from, $to), $r->get_data()['data']['metrics']);
    }

    public function test_history_and_bmlt_jobs(): void
    {
        $this->assertTrue(Sync::run('history', true)['ok']);
        $this->assertSame(count(self::MANAGED) * 4 + 2, Sync::state('history')['items'] + 0);
        $this->assertTrue(Sync::run('bmlt', true)['ok']);
        $this->assertSame('7', (string) Sync::state('bmlt')['items']);
    }

    public function test_failures_back_off_and_a_later_success_clears_them(): void
    {
        FakeService::$override = static fn () => FakeService::response(503, '{"error":{"code":"service_unavailable","message":"down"}}');
        $result = Sync::run('recent', false);
        $this->assertFalse($result['ok']);
        $state = Sync::state('recent');
        $this->assertSame('1', (string) $state['failures']);
        $this->assertStringContainsString('down', (string) $state['last_error']);
        $this->assertGreaterThan(time() + 60, strtotime($state['next_attempt'] . ' UTC'));
        $this->assertNotSame('', (string) $state['failed_areas']);

        $this->assertStringContainsString('Waiting to retry', Sync::run('recent', false)['message'], 'a scheduled run waits');
        FakeService::$override = null;
        $this->assertTrue(Sync::run('recent', true)['ok'], 'Sync now does not wait');
        $state = Sync::state('recent');
        $this->assertSame('0', (string) $state['failures']);
        $this->assertNull($state['next_attempt']);
    }

    public function test_an_unaccepted_credential_stops_the_run_at_once(): void
    {
        Settings::set_token('yapr_000000000000_' . str_repeat('z', 43));
        $before = count(FakeService::$log);
        $this->assertFalse(Sync::run('recent', true)['ok']);
        $this->assertSame(1, FakeService::apiRequests($before), 'no point asking 20 more times');
    }

    public function test_one_run_at_a_time_and_a_crashed_runs_lock_expires(): void
    {
        add_option('yapr_lock_job_recent', time(), '', false);
        $this->assertStringContainsString('already running', Sync::run('recent', true)['message']);
        update_option('yapr_lock_job_recent', time() - 3600);
        $this->assertTrue(Sync::run('recent', true)['ok']);
        $this->assertFalse(get_option('yapr_lock_job_recent'), 'released');
    }

    public function test_schedules_follow_the_interval_settings(): void
    {
        Sync::reschedule();
        foreach (Sync::JOBS as $job) {
            $this->assertNotFalse(wp_next_scheduled('yapr_sync_' . $job));
        }
        Settings::update(['interval_recent' => 1]);
        $this->assertSame(5, Sync::interval_minutes('recent'), 'never more often than every 5 minutes');
        Settings::update(['interval_bmlt' => 999999]);
        $this->assertSame(10080, Sync::interval_minutes('bmlt'));
        $this->assertSame(5 * MINUTE_IN_SECONDS, Sync::cron_schedules([])['yapr_recent']['interval']);
        Sync::unschedule();
        $this->assertFalse(wp_next_scheduled('yapr_sync_recent'));
    }

    public function test_old_cache_rows_are_pruned(): void
    {
        Reports::aggregate('summary', [1001], ['period' => '7d']);
        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->prefix}yapr_aggregate_cache SET fetched_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY)");
        Reports::aggregate('summary', [1002], ['period' => '7d']);
        $this->assertSame(1, Cache::prune());
        $this->assertSame(1, Cache::stats()['records']);
    }

    public function test_rate_limiting_by_the_service_is_a_failure_to_retry_later(): void
    {
        FakeService::$override = static fn () => FakeService::response(429, '{"error":{"code":"rate_limited","message":"Too many requests. Try again later."}}', ['retry-after' => '60']);
        $result = Sync::run('recent', false);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Too many requests', $result['message']);
        $this->assertSame('1', (string) Sync::state('recent')['failures']);
    }
}
