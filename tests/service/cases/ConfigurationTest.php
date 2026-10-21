<?php

declare(strict_types=1);

use FakeYap\Scenario;

/*
 * Settings that change how YAP's rows are read, against a real database server.
 */
return [
    'primary-key range narrowing gives the same answers as a full scan' => function (Harness $h): void {
        $narrow = $h->app(['use_id_range' => true]);
        $scan = $h->app(['use_id_range' => false]);
        $t1 = $h->token($narrow);
        $t2 = $h->token($scan);
        foreach ([['2026-08-01', '2026-08-01'], ['2026-08-15', '2026-09-03'], ['2026-09-30', '2026-09-30'], ['2026-10-01', '2026-10-05'], ['2026-07-01', '2026-07-31']] as [$from, $to]) {
            $q = Harness::range($from, $to);
            $a = $h->get($narrow, $t1, '/api/v1/summary', $q)['metrics'];
            $b = $h->get($scan, $t2, '/api/v1/summary', $q)['metrics'];
            Assert::same($b, $a, "$from..$to");
            [$x, $y] = $h->window($from, $to);
            Harness::sameFigures($h->scenario->expected(null, true, $x, $y), $a, "$from..$to");
        }
    },

    'participant clock offset is detected and removed on a server not set to UTC' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        $d = $h->get($app, $token, '/api/v1/summary', Harness::range('2026-09-01', '2026-09-30'));
        // Participant rows are stamped by the database clock (local time), events by YAP (UTC).
        Assert::same($h->dbOffset, $d['attribution']['participant_clock_offset_seconds']);
    },

    'answered_rule min_seconds turns very short accepted calls into connected_short' => function (Harness $h): void {
        $app = $h->app(['answered_rule' => 'min_seconds', 'answered_min_seconds' => 5]);
        $token = $h->token($app);
        [$a, $b] = $h->window('2026-08-01', '2026-09-30');
        $short = 0;
        foreach ($h->scenario->calls() as $c) {
            if ($c['flow'] === 'answered_short' && $c['start'] >= $a && $c['start'] < $b) {
                $short++;
            }
        }
        Assert::true($short > 0, 'the scenario has short calls');
        $e = $h->scenario->expected(null, true, $a, $b);
        $d = $h->get($app, $token, '/api/v1/summary', Harness::range('2026-08-01', '2026-09-30'));
        Assert::same($e['answered_calls'] - $short, $d['metrics']['answered_calls']['value']);
        Assert::same($short, $d['metrics']['connected_too_short_calls']['value']);
        Assert::true(str_contains($h->get($app, $token, '/api/v1/capabilities')['definitions']['answered_calls'], 'more than 5 seconds'));
    },

    'line mapping ties calls YAP stored without a service body to the Area of the line' => function (Harness $h): void {
        $app = $h->app(['attribution' => ['line_mapping' => [Scenario::LINES[1001] => 1001, Scenario::LINES[1002] => 1002]]]);
        $token = $h->token($app);
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        $expectedCalls = $h->scenario->expected([1001], false, $a, $b)['total_calls'];
        $expectedSearches = $h->scenario->expected([1001], false, $a, $b)['meeting_lookup_actions'];
        foreach ($h->scenario->calls() as $c) {
            if ($c['call'] !== null && $c['sb'] === null && $c['line'] === Scenario::LINES[1001] && $c['start'] >= $a && $c['start'] < $b) {
                $expectedCalls++;
                foreach ($c['interactions'] as [$key, $t]) {
                    if ($key === 'meeting_lookup_actions' && $t < $b) {
                        $expectedSearches++;
                    }
                }
            }
        }
        $m = $h->get($app, $token, '/api/v1/summary', Harness::range('2026-09-01', '2026-09-30') + ['areas' => '1001'])['metrics'];
        Assert::same($expectedCalls, $m['total_calls']['value'], 'automated calls on the Area line');
        Assert::same($expectedSearches, $m['meeting_lookup_actions']['value']);
        $sources = $h->get($app, $token, '/api/v1/summary', Harness::range('2026-09-01', '2026-09-30') + ['areas' => '1001'])['attribution']['policy_sources'];
        Assert::true(($sources['line_mapping'] ?? 0) > 0, 'reported as attributed by line mapping');
    },

    'the report cache never mixes credentials or Areas' => function (Harness $h): void {
        $app = $h->app(['cache_ttl_seconds' => 300]);
        $wide = $h->token($app);
        $narrow = $h->token($app, [1002], null, false);
        $q = Harness::range('2026-09-01', '2026-09-30');
        $first = $h->get($app, $wide, '/api/v1/summary', $q)['metrics']['total_calls']['value'];
        $second = $h->get($app, $narrow, '/api/v1/summary', $q)['metrics']['total_calls']['value'];
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        Assert::same($h->scenario->expected(null, true, $a, $b)['total_calls'], $first);
        Assert::same($h->scenario->expected([1002], false, $a, $b)['total_calls'], $second);
        Assert::same($first, $h->get($app, $wide, '/api/v1/summary', $q)['metrics']['total_calls']['value'], 'cached copy');
    },
];
