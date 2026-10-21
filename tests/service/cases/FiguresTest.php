<?php

declare(strict_types=1);

use YapReporting\Auth\Client;

/*
 * Every figure the service reports, compared with the scenario's independent oracle.
 */
return [
    'summary matches the scenario for every Area, month and combination' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        $areaSets = [[1001], [1002], [1003], [1004], [2001], [1001, 1002], [1001, 1002, 1003, 1004]];
        foreach ([['2026-08-01', '2026-08-31'], ['2026-09-01', '2026-09-30'], ['2026-09-14', '2026-09-20'], ['2026-09-30', '2026-09-30']] as [$from, $to]) {
            [$a, $b] = $h->window($from, $to);
            foreach ($areaSets as $areas) {
                $m = $h->get($app, $token, '/api/v1/summary', Harness::range($from, $to) + ['areas' => implode(',', $areas)])['metrics'];
                Harness::sameFigures($h->scenario->expected($areas, false, $a, $b), $m, "areas " . implode(',', $areas) . " $from..$to");
            }
        }
    },

    'region-wide figures include unassigned activity and count no text message as a call' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        foreach ([['2026-08-01', '2026-09-30'], ['2026-09-01', '2026-09-30']] as [$from, $to]) {
            [$a, $b] = $h->window($from, $to);
            $m = $h->get($app, $token, '/api/v1/summary', Harness::range($from, $to))['metrics'];
            Harness::sameFigures($h->scenario->expected(null, true, $a, $b), $m, "all service bodies $from..$to");
            $m = $h->get($app, $token, '/api/v1/summary', Harness::range($from, $to) + ['areas' => '1001,1002', 'include_unassigned' => 'true'])['metrics'];
            Harness::sameFigures($h->scenario->expected([1001, 1002], true, $a, $b), $m, "1001,1002 + unassigned $from..$to");
        }
        // The scenario has hundreds of texts; none may appear as a call that ended in the menu.
        $texts = count(array_filter($h->scenario->calls(), static fn (array $c): bool => $c['call'] === null));
        Assert::true($texts > 100, 'the scenario has text messages');
    },

    'daily trends match, every day zero-filled' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        foreach ([[[1001, 1002], false], [null, true]] as [$areas, $unassigned]) {
            $q = Harness::range('2026-08-01', '2026-09-30') + ['granularity' => 'day'] + ($areas !== null ? ['areas' => implode(',', $areas)] : []);
            $series = $h->get($app, $token, '/api/v1/trends', $q)['series'];
            $expected = $h->scenario->expectedDaily($areas, $unassigned);
            Assert::same(count($expected), count($series), 'one bucket per day');
            foreach ($series as $row) {
                foreach ($expected[$row['bucket']] as $k => $v) {
                    Assert::same($v, $row[$k], $row['bucket'] . ' ' . $k);
                }
            }
        }
    },

    'monthly trends add up to the monthly summaries' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        $series = $h->get($app, $token, '/api/v1/trends', Harness::range('2026-08-01', '2026-09-30') + ['granularity' => 'month', 'areas' => '1001,1002,1003,1004'])['series'];
        Assert::same(['2026-08', '2026-09'], array_column($series, 'bucket'));
        foreach ([['2026-08-01', '2026-08-31', 0], ['2026-09-01', '2026-09-30', 1]] as [$from, $to, $i]) {
            [$a, $b] = $h->window($from, $to);
            $e = $h->scenario->expected([1001, 1002, 1003, 1004], false, $a, $b);
            Assert::same($e['total_calls'], $series[$i]['total_calls']);
            Assert::same($e['answered_calls'], $series[$i]['answered_calls']);
            Assert::same($e['answer_rate'], $series[$i]['answer_rate'] === null ? null : $series[$i]['answer_rate'], 'monthly answer rate');
        }
    },

    'volunteer statistics match, named from YAP lists, deleted lists ignored' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        foreach ([[1001], [1002], [1003], [1001, 1002, 1003, 1004, 2001]] as $areas) {
            [$a, $b] = $h->window('2026-09-01', '2026-09-30');
            $rows = $h->get($app, $token, '/api/v1/volunteer-summary', Harness::range('2026-09-01', '2026-09-30') + ['areas' => implode(',', $areas)])['volunteers'];
            $got = [];
            foreach ($rows as $r) {
                Assert::true(preg_match('/^v_[0-9a-f]{16}$/', $r['volunteer_id']) === 1, 'pseudonymous id');
                $got[$r['name'] ?? $r['number_hint']] = $r;
            }
            $expected = $h->scenario->expectedVolunteers($areas, false, $a, $b);
            ksort($got);
            Assert::same(array_keys($expected), array_keys(array_intersect_key($got, $expected)), 'every volunteer appears');
            Assert::same(count($expected), count($got), 'no one else appears: ' . implode(', ', array_keys(array_diff_key($got, $expected))));
            foreach ($expected as $name => $fields) {
                foreach ($fields as $f => $v) {
                    Assert::same($v, $got[$name][$f], "$name $f");
                }
            }
            Assert::true(!isset($got['Deleted Name']), 'a deleted list is ignored');
        }
    },

    'group volunteers are named and unnamed volunteers show only four digits' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        $rows = $h->get($app, $token, '/api/v1/volunteer-summary', Harness::range('2026-08-01', '2026-09-30') + ['areas' => '1002,1003'])['volunteers'];
        $names = array_column($rows, 'name');
        Assert::true(in_array('Quinn Hillcrest Group', $names, true), 'group volunteer named');
        $unnamed = array_values(array_filter($rows, static fn ($r) => $r['name'] === null));
        Assert::same(1, count($unnamed));
        Assert::same('…0133', $unnamed[0]['number_hint']);
    },

    'call list outcomes follow the call flows' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        $outcome = [
            'answered_blast' => 'answered', 'answered_single' => 'answered', 'answered_short' => 'answered', 'answered_conference_record' => 'answered',
            'voicemail' => 'voicemail', 'voicemail_pickup' => 'voicemail', 'caller_left_ringing' => 'not_answered', 'force_dialed_busy_bad' => 'not_answered',
            'no_response_logged' => 'no_volunteer_response_logged', 'outcome_not_recorded' => 'outcome_not_recorded', 'forwarded' => 'forwarded',
            'not_routed' => 'not_routed', 'meeting_search' => 'automated', 'meeting_search_attributed' => 'automated', 'jft' => 'automated',
            'spad' => 'automated', 'dialback' => 'dialback', 'menu_only' => 'ended_before_selection',
        ];
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        $expected = [];
        foreach ($h->scenario->calls() as $c) {
            if ($c['call'] !== null && $c['start'] >= $a && $c['start'] < $b) {
                $o = $outcome[$c['flow']];
                $expected[$o] = ($expected[$o] ?? 0) + 1;
            }
        }
        ksort($expected);
        $got = [];
        foreach (array_keys($expected) as $o) {
            $d = $h->get($app, $token, '/api/v1/calls', Harness::range('2026-09-01', '2026-09-30') + ['outcome' => $o, 'per_page' => '7']);
            $got[$o] = $d['pagination']['total'];
            Assert::true(count($d['calls']) <= 7, 'page size');
            foreach ($d['calls'] as $call) {
                Assert::same($o, $call['outcome']);
            }
        }
        Assert::same($expected, $got);
        $all = $h->get($app, $token, '/api/v1/calls', Harness::range('2026-09-01', '2026-09-30') + ['per_page' => '200', 'page' => '2', 'sort' => 'asc']);
        Assert::same(array_sum($expected), $all['pagination']['total']);
        Assert::same((int) ceil(array_sum($expected) / 200), $all['pagination']['total_pages']);
    },

    'Area dashboard figures and labelled server-wide totals' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app, [1001, 1002], null, true);
        $d = $h->get($app, $token, '/api/v1/area-dashboard', Harness::range('2026-09-01', '2026-09-30') + ['area_id' => '1001'])['current'];
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        $e = $h->scenario->expected([1001], false, $a, $b);
        $all = $h->scenario->expected(null, true, $a, $b);
        Assert::same($e['volunteer_routed_calls'], $d['volunteer_calls']['value']);
        Assert::same($e['answered_calls'], $d['calls_answered']['value']);
        Assert::same($e['answer_rate'], $d['answer_rate']['value']);
        Assert::same($e['meeting_lookup_calls'], $d['meeting_lookups']['value'], 'meeting searches YAP tied to the Area');
        Assert::same($all['meeting_lookup_calls'], $d['meeting_lookups_installation_wide']['value']);
        Assert::same('installation', $d['meeting_lookups_installation_wide']['scope']);
        Assert::same($all['jft_calls'], $d['jft_installation_wide']['value']);
        Assert::same($all['jft_sms'], $d['sms_jft_installation_wide']['value']);
        Assert::same($all['spad_sms'], $d['sms_spad_installation_wide']['value']);
        Assert::same($all['sms_volunteer_requests'], $d['sms_volunteer_lookups_installation_wide']['value']);
        Assert::same(0, $d['jft']['value'], 'YAP stores JFT lookups without a service body');
    },

    'previous period comparison uses the period before' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        $d = $h->get($app, $token, '/api/v1/summary', Harness::range('2026-09-01', '2026-09-30') + ['areas' => '1002', 'compare' => 'true']);
        [$a, $b] = $h->window('2026-08-02', '2026-08-31');
        Assert::same('2026-08-02', $d['previous_period']['range']['start_date']);
        Assert::same($h->scenario->expected([1002], false, $a, $b)['total_calls'], $d['previous_period']['metrics']['total_calls']['value']);
    },

    'meeting lookups, meditations and texts by topic' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        $e = $h->scenario->expected(null, true, $a, $b);
        $q = Harness::range('2026-09-01', '2026-09-30');
        $m = $h->get($app, $token, '/api/v1/meeting-lookups', $q);
        Assert::same($e['meeting_lookup_actions'], $m['meeting_lookup_actions']['value']);
        Assert::same($e['sms_meeting_lookups'], $m['sms_meeting_lookups']['value']);
        Assert::same(false, $m['successful_vs_failed']['available'], 'YAP does not record results');
        $by = [];
        foreach ($m['by_service_body'] as $row) {
            $by[$row['service_body_id'] ?? 'unassigned'] = $row['voice_actions'];
        }
        Assert::same($h->scenario->expected([1001], false, $a, $b)['meeting_lookup_actions'], $by[1001] ?? 0, 'older builds tie some searches to an Area');
        $med = $h->get($app, $token, '/api/v1/meditations', $q);
        Assert::same($e['jft_voice_plays'], $med['area_attributed']['jft_voice']['value']);
        Assert::same($e['spad_sms'], $med['area_attributed']['spad_sms']['value']);
        Assert::same($e['jft_voice_plays'] + $e['jft_sms'] + $e['spad_voice_plays'] + $e['spad_sms'], $med['installation_wide']['combined']['value']);
        $sms = $h->get($app, $token, '/api/v1/sms-summary', $q);
        Assert::same($e['sms_meeting_lookups'], $sms['sms_meeting_lookups']['value']);
        Assert::same($e['sms_volunteer_requests'], $sms['sms_volunteer_requests']['value']);
        Assert::same($e['jft_sms'], $sms['sms_jft_requests']['value']);
    },

    'distributions count every call once, in the reporting time zone' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        $d = $h->get($app, $token, '/api/v1/distributions', Harness::range('2026-09-01', '2026-09-30') + ['areas' => '1001']);
        $hours = array_fill(0, 24, 0);
        foreach ($h->scenario->calls() as $c) {
            if ($c['call'] !== null && $c['sb'] === 1001 && $c['start'] >= $a && $c['start'] < $b) {
                $hours[(int) (new DateTimeImmutable('@' . $c['start']))->setTimezone(new DateTimeZone(Harness::TZ))->format('G')]++;
            }
        }
        Assert::same($hours, array_column($d['by_hour'], 'calls'));
        // Answer rate per weekday, by the summary's definition (outcomes YAP did not record are left out).
        $days = array_fill(0, 7, [0, 0]);
        foreach ($h->scenario->calls() as $c) {
            if ($c['call'] !== null && $c['sb'] === 1001 && $c['start'] >= $a && $c['start'] < $b) {
                $w = (int) (new DateTimeImmutable('@' . $c['start']))->setTimezone(new DateTimeZone(Harness::TZ))->format('w');
                $days[$w][0] += $c['call']['answered_calls'];
                $days[$w][1] += $c['call']['volunteer_routed_calls'] - $c['call']['routed_outcome_not_recorded_calls'];
            }
        }
        Assert::same(array_map(static fn ($v) => $v[1] > 0 ? round($v[0] * 100 / $v[1], 1) : null, $days), array_column($d['by_weekday'], 'answer_rate'));
        Assert::same(array_sum($hours), $d['weekday_calls'] + $d['weekend_calls']);
    },

    'areas endpoint lists every service body in scope with its activity' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app, [1001, 1004], [Client::SCOPE_SUMMARY], false);
        $rows = $h->get($app, $token, '/api/v1/areas', Harness::range('2026-09-01', '2026-09-30'))['service_bodies'];
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        Assert::same([1001, 1004], array_column($rows, 'service_body_id'));
        Assert::same($h->scenario->expected([1004], false, $a, $b)['total_calls'], $rows[1]['calls']);
    },
];
