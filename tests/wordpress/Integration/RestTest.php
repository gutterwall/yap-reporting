<?php

declare(strict_types=1);

namespace YAPR\Tests\Integration;

use YAPR\Areas;
use YAPR\Settings;
use YAPR\Tests\FakeService;
use YAPR\Tests\TestCase;

/**
 * The dashboards' REST routes, end to end through the webservice and the fake YAP database: figures, and who may
 * see what. Every Area a request names is checked against the current user's grant, never the site credential's.
 */
final class RestTest extends TestCase
{
    public function test_figures_reach_the_dashboard_unchanged(): void
    {
        [$from, $to] = self::lastDays(30);
        foreach ([[1001], [1002], [1001, 1003]] as $areas) {
            $r = $this->rest('/report', ['view' => 'summary', 'areas' => implode(',', $areas), 'start_date' => $from, 'end_date' => $to], self::$admin);
            $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
            $this->assertFigures(FakeService::expected($areas, false, $from, $to), $r->get_data()['data']['metrics'], implode(',', $areas));
        }
    }

    public function test_all_my_areas_includes_region_wide_activity_for_users_who_see_every_area(): void
    {
        [$from, $to] = self::lastDays(30);
        $r = $this->rest('/report', ['view' => 'summary', 'start_date' => $from, 'end_date' => $to], self::$phoneline);
        $this->assertFigures(FakeService::expected(self::MANAGED, true, $from, $to), $r->get_data()['data']['metrics']);

        // Someone who sees only some Areas never gets activity that belongs to the whole Region.
        $r = $this->rest('/report', ['view' => 'summary', 'start_date' => $from, 'end_date' => $to], self::$reporter);
        $this->assertFigures(FakeService::expected([1001], false, $from, $to), $r->get_data()['data']['metrics']);

        Settings::update(['include_unassigned' => 0]);
        $r = $this->rest('/report', ['view' => 'summary', 'start_date' => $from, 'end_date' => $to], self::$phoneline);
        $this->assertFigures(FakeService::expected(self::MANAGED, false, $from, $to), $r->get_data()['data']['metrics'], 'setting off');
    }

    public function test_region_expands_to_its_selected_areas(): void
    {
        [$from, $to] = self::lastDays(7);
        $r = $this->rest('/report', ['view' => 'summary', 'region' => 1000, 'start_date' => $from, 'end_date' => $to], self::$admin);
        $this->assertSame([1000, 1001, 1002, 1003], array_keys($r->get_data()['areas']), 'in order; Valley Area (1004) is not selected on this site');
        $this->assertFigures(FakeService::expected(self::MANAGED, false, $from, $to), $r->get_data()['data']['metrics']);
    }

    public function test_period_trends_and_the_area_dashboard(): void
    {
        $r = $this->rest('/report', ['view' => 'trends', 'areas' => '1001', 'period' => '7d'], self::$reporter);
        $this->assertSame(200, $r->get_status());
        $series = $r->get_data()['data']['series'];
        $this->assertCount(7, $series);
        $daily = FakeService::$scenario->expectedDaily([1001], false);
        foreach ($series as $row) {
            $this->assertSame($daily[$row['bucket']]['total_calls'], $row['total_calls'], $row['bucket']);
        }
        $r = $this->rest('/report', ['view' => 'area-dashboard', 'areas' => '1001', 'period' => '30d'], self::$reporter);
        [$from, $to] = self::lastDays(30);
        $e = FakeService::expected([1001], false, $from, $to);
        $this->assertSame($e['answered_calls'], $r->get_data()['data']['current']['calls_answered']['value']);
        $this->assertArrayHasKey('previous', $r->get_data()['data'], 'the dashboard always compares');
    }

    public function test_areas_outside_the_grant_are_refused_not_dropped(): void
    {
        $r = $this->rest('/report', ['view' => 'summary', 'areas' => '1002'], self::$reporter);
        $this->assertSame(403, $r->get_status());
        $this->assertSame('yapr_area_forbidden', $r->get_data()['code']);
        $this->assertSame(403, $this->rest('/report', ['view' => 'summary', 'areas' => '1001,1002'], self::$reporter)->get_status());
        $this->assertSame(403, $this->rest('/report', ['view' => 'summary', 'region' => 1000], self::$reporter)->get_status());
        $this->assertSame(403, $this->rest('/report', ['view' => 'summary', 'areas' => '1004'], self::$admin)->get_status(), 'in BMLT but not selected on this site');
        $this->assertSame(404, $this->rest('/report', ['view' => 'summary', 'areas' => '999999'], self::$admin)->get_status());
        $this->assertSame(200, $this->rest('/report', ['view' => 'summary', 'areas' => '1002'], self::$reporter1002)->get_status(), 'personal grant');
        $this->assertSame(403, $this->rest('/report', ['view' => 'summary', 'areas' => '1001'], self::$reporter1002)->get_status(), 'the personal grant replaces the role grant');
    }

    public function test_people_without_permission_get_nothing(): void
    {
        foreach ([0, self::$subscriber, self::$editor] as $user) {
            $this->assertContains($this->rest('/report', ['view' => 'summary'], $user)->get_status(), [401, 403]);
            $this->assertContains($this->rest('/calls', [], $user)->get_status(), [401, 403]);
            $this->assertContains($this->rest('/volunteers', [], $user)->get_status(), [401, 403]);
        }
        $this->assertSame(403, $this->rest('/calls', [], self::$reporter)->get_status(), 'reporters see aggregates only');
        $this->assertSame(403, $this->rest('/volunteers', [], self::$reporter)->get_status());
    }

    public function test_malformed_parameters_are_rejected(): void
    {
        foreach ([['view' => 'users'], ['view' => 'summary', 'areas' => '1 OR 1=1'], ['view' => 'summary', 'period' => 'forever'], ['view' => 'summary', 'start_date' => '2026-01-01']] as $params) {
            $this->assertContains($this->rest('/report', $params, self::$admin)->get_status(), [400], wp_json_encode($params));
        }
    }

    public function test_call_list_and_detail_follow_the_users_grant_and_permissions(): void
    {
        [$from, $to] = self::lastDays(30);
        $r = $this->rest('/calls', ['areas' => '1001', 'start_date' => $from, 'end_date' => $to, 'answered' => true, 'per_page' => 50], self::$phoneline);
        $this->assertSame(200, $r->get_status());
        $data = $r->get_data();
        $this->assertSame(FakeService::expected([1001], false, $from, $to)['answered_calls'], $data['pagination']['total']);
        $call = $data['calls'][0];
        $this->assertSame('Riverside Area', $call['area_name']);
        $this->assertArrayHasKey('answered_by', $call, 'phoneline administrators see volunteers');
        $this->assertNotEmpty($call['answered_by']['name']);
        $this->assertSame('no-store', explode(', ', $r->get_headers()['Cache-Control'])[1]);

        $detail = $this->rest('/calls/' . $call['id'], [], self::$phoneline);
        $this->assertSame(200, $detail->get_status());
        $this->assertSame('Riverside Area', $detail->get_data()['area']);

        // Someone allowed call details for Area 1002 only cannot open an Area 1001 call by its id.
        $limited = self::factory()->user->create(['role' => \YAPR\Installer::ROLE_PHONELINE_ADMIN]);
        update_user_meta($limited, \YAPR\Access::USER_META, [1002]);
        $this->assertSame(404, $this->rest('/calls/' . $call['id'], [], $limited)->get_status());

        Settings::update(['show_volunteer_names' => 0]);
        $hidden = $this->rest('/calls/' . $call['id'], [], self::$phoneline)->get_data()['call'];
        $this->assertArrayNotHasKey('name', $hidden['answered_by']);
        $this->assertArrayHasKey('volunteer_id', $hidden['answered_by']);
    }

    public function test_calls_without_a_service_body_can_be_opened_from_all_my_areas(): void
    {
        [$from, $to] = self::lastDays(30);
        $r = $this->rest('/calls', ['start_date' => $from, 'end_date' => $to, 'category' => 'meeting_lookup', 'per_page' => 5], self::$phoneline);
        $this->assertSame(200, $r->get_status());
        $calls = array_values(array_filter($r->get_data()['calls'], static fn ($c) => null === $c['service_body_id']));
        $this->assertNotEmpty($calls, 'All my Areas lists region-wide calls');
        $this->assertSame('Unassigned', $calls[0]['area_name']);
        $detail = $this->rest('/calls/' . $calls[0]['id'], [], self::$phoneline);
        $this->assertSame(200, $detail->get_status(), wp_json_encode($detail->get_data()));

        // Not for someone who only sees some Areas.
        $limited = self::factory()->user->create(['role' => \YAPR\Installer::ROLE_PHONELINE_ADMIN]);
        update_user_meta($limited, \YAPR\Access::USER_META, [1001]);
        $this->assertSame(404, $this->rest('/calls/' . $calls[0]['id'], [], $limited)->get_status());
    }

    public function test_volunteer_report_names_and_explanations(): void
    {
        [$from, $to] = self::lastDays(30);
        $r = $this->rest('/volunteers', ['areas' => '1001,1002', 'start_date' => $from, 'end_date' => $to], self::$phoneline);
        $this->assertSame(200, $r->get_status());
        $data = $r->get_data()['data'];
        $this->assertSame('ok', $data['names_status']);
        $tz = new \DateTimeZone('America/New_York');
        $expected = FakeService::$scenario->expectedVolunteers([1001, 1002], false, (new \DateTimeImmutable($from, $tz))->getTimestamp(), (new \DateTimeImmutable($to, $tz))->modify('+1 day')->getTimestamp());
        $got = [];
        foreach ($data['volunteers'] as $v) {
            $got[$v['name'] ?? $v['number_hint']] = $v['answered_calls'];
        }
        ksort($got);
        $this->assertSame(array_map(static fn ($v) => $v['answered_calls'], $expected), $got);

        Settings::update(['show_volunteer_names' => 0]);
        $data = $this->rest('/volunteers', ['areas' => '1001'], self::$phoneline)->get_data()['data'];
        $this->assertSame('setting_off', $data['names_status']);
        foreach ($data['volunteers'] as $v) {
            $this->assertArrayNotHasKey('name', $v);
            $this->assertArrayNotHasKey('number_hint', $v);
        }

        // A credential without the volunteer_names permission: the plugin says why names are missing.
        Settings::update(['show_volunteer_names' => 1]);
        Settings::set_token(FakeService::credential(true, [], ['summary', 'call_details', 'volunteer_stats'], true));
        $this->assertSame('permission_missing', $this->rest('/volunteers', ['areas' => '1001'], self::$phoneline)->get_data()['data']['names_status']);
    }

    public function test_the_credential_never_reaches_a_browser_or_an_error(): void
    {
        [$from, $to] = self::lastDays(7);
        $bodies = '';
        foreach (['summary', 'trends', 'distributions', 'area-dashboard', 'meeting-lookups', 'meditations', 'sms-summary'] as $view) {
            $bodies .= wp_json_encode($this->rest('/report', ['view' => $view, 'areas' => '1001', 'start_date' => $from, 'end_date' => $to], self::$admin)->get_data());
        }
        $bodies .= wp_json_encode($this->rest('/calls', [], self::$admin)->get_data());
        $this->assertStringNotContainsString(FakeService::$token, $bodies);
        $this->assertStringNotContainsString('yapr_', str_replace('"yapr_', '', $bodies), 'no token fragment');

        // An upstream failure that echoes the token is scrubbed before display.
        FakeService::$override = static fn () => FakeService::response(500, (string) wp_json_encode(['error' => ['code' => 'internal_error', 'message' => 'failed for Bearer ' . FakeService::$token]]));
        $r = $this->rest('/report', ['view' => 'summary', 'areas' => '1001', 'period' => 'yesterday'], self::$admin);
        $this->assertSame(500, $r->get_status());
        $this->assertStringNotContainsString(FakeService::$token, wp_json_encode($r->get_data()));
        $this->assertSame(1, count(array_filter(array_slice(FakeService::$log, -1), static fn ($l) => 'Bearer ' . FakeService::$token === $l['authorization'])), 'the token was sent in the header');
    }

    public function test_upstream_authentication_problems_are_reported_as_a_site_problem(): void
    {
        Settings::set_token('yapr_000000000000_' . str_repeat('x', 43));
        $r = $this->rest('/report', ['view' => 'summary', 'areas' => '1001', 'period' => 'today'], self::$admin);
        $this->assertSame(502, $r->get_status(), 'not 401: the visitor is logged in fine');
        $this->assertSame('yapr_api_unauthorized', $r->get_data()['code']);
    }

    public function test_area_names_come_from_bmlt_or_the_sites_own_labels(): void
    {
        $this->assertSame('Riverside Area', Areas::name(1001));
        Settings::update(['area_labels' => [1001 => 'Riverside (our name)']]);
        $r = $this->rest('/report', ['view' => 'summary', 'areas' => '1001', 'period' => 'today'], self::$admin);
        $this->assertSame(['1001' => 'Riverside (our name)'], $r->get_data()['areas']);
    }
}
