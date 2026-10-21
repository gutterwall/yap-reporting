<?php

declare(strict_types=1);

use YapReporting\Auth\Client;

$q = ['start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'timezone' => 'UTC'];

return [
    'Area 10 summary figures' => function () use ($q): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Area 10', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $m = $app->handle(Fixture::request('/api/v1/summary', $q + ['areas' => '10'], $token))->body['data']['metrics'];
        Assert::same(3, $m['total_calls']['value'], 'unique calls');
        Assert::same(3, $m['volunteer_routed_calls']['value']);
        Assert::same(2, $m['answered_calls']['value'], 'the 4-second call counts: YAP rule, volunteer accepted');
        Assert::same(false, $m['connected_too_short_calls']['available'], 'not used under the YAP rule');
        Assert::same(1, $m['volunteer_calls_to_voicemail']['value']);
        Assert::same(66.7, $m['answer_rate']['value']);
        Assert::same(4, $m['volunteer_dial_attempts']['value']);
        Assert::same(2, $m['attempts_not_answered']['value']);
        Assert::same(124, $m['total_conversation_seconds']['value']);
        Assert::same(1, $m['sms_meeting_lookups']['value']);
        Assert::same(0, $m['meeting_lookup_calls']['value'], 'unattributed searches are not assigned to Area 10');
    },
    'installation-wide meditation totals are labelled separately' => function () use ($q): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Area 10', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $d = $app->handle(Fixture::request('/api/v1/area-dashboard', $q + ['area_id' => '10'], $token))->body['data']['current'];
        Assert::same(0, $d['jft']['value']);
        Assert::same('area', $d['jft']['scope']);
        Assert::same(1, $d['jft_installation_wide']['value']);
        Assert::same(0, $d['meeting_lookups']['value'], 'searches carry no service body');
        Assert::same(1, $d['meeting_lookups_installation_wide']['value'], 'server-wide total is returned and labelled');
        Assert::same('installation', $d['meeting_lookups_installation_wide']['scope']);
        Assert::same('installation', $d['jft_installation_wide']['scope']);
        Assert::same(3, $d['volunteer_calls']['value']);
        Assert::same(2, $d['calls_answered']['value']);
        Assert::same(1, $d['sms_meeting_lookups']['value']);
        Assert::same(0, $d['sms_volunteer_lookups']['value']);
        Assert::same(0, $d['sms_jft']['value']);
        Assert::same(false, $d['sms_spad']['available'], 'fixture YAP build has no SPAD_LOOKUP_SMS');
    },
    'all-access client sees unassigned meeting searches' => function () use ($q): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Region', true, [], true, [Client::SCOPE_SUMMARY], []);
        $m = $app->handle(Fixture::request('/api/v1/summary', $q, $token))->body['data']['metrics'];
        Assert::same(8, $m['total_calls']['value'], 'linked volunteer legs are folded into their parent calls');
        Assert::same(1, $m['meeting_lookup_calls']['value']);
        Assert::same(2, $m['meeting_lookup_actions']['value']);
        Assert::same(3, $m['answered_calls']['value']);
    },
    'volunteer summary attributes answers' => function () use ($q): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Admin', true, [], true, Client::ALL_SCOPES, []);
        $d = $app->handle(Fixture::request('/api/v1/volunteer-summary', $q, $token))->body['data'];
        $byName = [];
        foreach ($d['volunteers'] as $v) {
            $byName[$v['name']] = $v;
        }
        Assert::same(1, $byName['Blair Example']['answered_calls']);
        Assert::same(120, $byName['Blair Example']['total_conversation_seconds']);
        Assert::same(2, $byName['Alex Example']['answered_calls'], 'short call (number on the accept event) and Area 20 call (inferred)');
        Assert::same(5, $byName['Alex Example']['routed_attempts']);
        Assert::same(1, $byName['Alex Example']['busy_attempts']);
        Assert::same(1, $byName['Blair Example']['bad_number_attempts']);
        Assert::same(1, $d['answers_attributed_by_inference']);
    },
    'trends are zero-filled per day' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Region', true, [], true, [Client::SCOPE_SUMMARY], []);
        $d = $app->handle(Fixture::request('/api/v1/trends', ['start_date' => '2026-06-08', 'end_date' => '2026-06-12', 'timezone' => 'UTC'], $token))->body['data'];
        Assert::same(5, count($d['series']));
        Assert::same(0, $d['series'][0]['total_calls']);
        Assert::same(8, $d['series'][2]['total_calls']);
    },
    'calls list paginates and filters' => function () use ($q): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Admin', true, [], true, Client::ALL_SCOPES, []);
        $d = $app->handle(Fixture::request('/api/v1/calls', $q + ['answered' => 'true'], $token))->body['data'];
        Assert::same(3, $d['pagination']['total']);
        $d = $app->handle(Fixture::request('/api/v1/calls', $q + ['per_page' => '4', 'page' => '2'], $token))->body['data'];
        Assert::same(4, count($d['calls']));
        Assert::same(2, $d['pagination']['total_pages']);
        $d = $app->handle(Fixture::request('/api/v1/calls', $q + ['per_page' => '3', 'page' => '3'], $token))->body['data'];
        Assert::same(2, count($d['calls']));
        Assert::same(3, $d['pagination']['total_pages']);
    },
    'YAP leg cache supplies Area when events have none' => function () use ($q): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Area 30', false, [30], false, [Client::SCOPE_SUMMARY], []);
        $m = $app->handle(Fixture::request('/api/v1/summary', $q + ['areas' => '30'], $token))->body['data']['metrics'];
        Assert::same(1, $m['total_calls']['value']);
        Assert::same(1, $m['volunteer_routed_calls']['value']);
    },
    'primary-key range narrowing returns the same calls as a full scan' => function (): void {
        foreach ([true, false] as $useIdRange) {
            [$app] = Fixture::app(['use_id_range' => $useIdRange]);
            [, $token] = $app->clients()->create('Region', true, [], true, [Client::SCOPE_SUMMARY], []);
            $day = fn (string $d): int => $app->handle(Fixture::request('/api/v1/summary', ['start_date' => $d, 'end_date' => $d, 'timezone' => 'UTC'], $token))->body['data']['metrics']['total_calls']['value'];
            Assert::same(1, $day('2026-05-01'), 'old call');
            Assert::same(8, $day('2026-06-10'), 'main day');
            Assert::same(1, $day('2026-07-01'), 'new call');
            Assert::same(0, $day('2026-06-20'), 'empty day');
        }
    },
    'force-dial, busy and bad-number events' => function () use ($q): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Area 40', false, [40], false, [Client::SCOPE_SUMMARY], []);
        $m = $app->handle(Fixture::request('/api/v1/summary', $q + ['areas' => '40'], $token))->body['data']['metrics'];
        Assert::same(1, $m['total_calls']['value'], 'busy / bad-number legs are not calls');
        Assert::same(1, $m['volunteer_requested_calls']['value'], 'force dial counts as asking for a volunteer');
        Assert::same(2, $m['volunteer_dial_attempts']['value']);
        Assert::same(1, $m['attempts_busy']['value']);
        Assert::same(1, $m['attempts_bad_number']['value']);
        Assert::same(0, $m['answered_calls']['value']);
        Assert::same(1, $m['abandoned_volunteer_calls']['value']);
    },
    'forwarding to an outside helpline is its own outcome, not abandoned or missed' => function () use ($q): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Area 50', false, [50], false, [Client::SCOPE_SUMMARY, Client::SCOPE_CALL_DETAILS], []);
        $m = $app->handle(Fixture::request('/api/v1/summary', $q + ['areas' => '50'], $token))->body['data']['metrics'];
        Assert::same(1, $m['total_calls']['value']);
        Assert::same(1, $m['volunteer_requested_calls']['value']);
        Assert::same(1, $m['forwarded_to_helpline_calls']['value']);
        Assert::same(0, $m['volunteer_routed_calls']['value']);
        Assert::same(0, $m['abandoned_volunteer_calls']['value']);
        Assert::same(null, $m['answer_rate']['value'], 'no routed calls, so no answer rate');
        $calls = $app->handle(Fixture::request('/api/v1/calls', $q + ['areas' => '50'], $token))->body['data']['calls'];
        Assert::same('forwarded', $calls[0]['outcome']);
        $d = $app->handle(Fixture::request('/api/v1/area-dashboard', $q + ['area_id' => '50'], $token))->body['data']['current'];
        Assert::same(1, $d['calls_forwarded']['value']);
    },
    'optional min_seconds rule still available' => function () use ($q): void {
        [$app] = Fixture::app(['answered_rule' => 'min_seconds']);
        [, $token] = $app->clients()->create('Area 10', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $m = $app->handle(Fixture::request('/api/v1/summary', $q + ['areas' => '10'], $token))->body['data']['metrics'];
        Assert::same(1, $m['answered_calls']['value']);
        Assert::same(1, $m['connected_too_short_calls']['value']);
    },
    'finds the group volunteer whose phone picks up first and never accepts' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Admin', true, [], true, Client::ALL_SCOPES, []);
        $d = $app->handle(Fixture::request('/api/v1/volunteer-summary', ['start_date' => '2026-06-11', 'end_date' => '2026-06-11', 'timezone' => 'UTC'], $token))->body['data'];
        $by = [];
        foreach ($d['volunteers'] as $v) {
            $by[$v['name'] ?? $v['number_hint']] = $v;
        }
        Assert::true(isset($by['Casey Example']), 'group volunteer name resolved (number stored as a JSON number)');
        $c = $by['Casey Example'];
        Assert::same(3, $c['picked_up']);
        Assert::same(3, $c['pickups_on_unanswered_calls']);
        Assert::same(0, $c['pickups_taken_by_other']);
        Assert::same(3, $c['declined_without_key']);
        Assert::same(3, $c['calls_lost_after_first_pickup']);
        Assert::same(true, $c['possible_voicemail_pickup']);
        Assert::same('…0173', $c['number_hint']);
        Assert::same(0, $by['Alex Example']['calls_lost_after_first_pickup']);
        Assert::same(false, $by['Alex Example']['possible_voicemail_pickup']);
    },
    'call blast: credits the volunteer who accepted, not the first pick-up; corrects the participant clock' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Admin', true, [], true, Client::ALL_SCOPES, []);
        $q = ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'timezone' => 'UTC'];
        $calls = $app->handle(Fixture::request('/api/v1/calls', $q + ['areas' => '70'], $token))->body['data']['calls'];
        Assert::same(1, count($calls));
        Assert::same(true, $calls[0]['answered']);
        Assert::same('Blair Example', $calls[0]['answered_by']['name']);
        Assert::same(88, $calls[0]['conversation_seconds'], 'accept at :12, hang-up at 1:40');
        $d = $app->handle(Fixture::request('/api/v1/volunteer-summary', $q + ['areas' => '70'], $token))->body['data'];
        $by = [];
        foreach ($d['volunteers'] as $v) {
            $by[$v['name']] = $v;
        }
        Assert::same(1, $by['Blair Example']['answered_calls']);
        Assert::same(0, $by['Alex Example']['answered_calls'], 'first to pick up, but did not accept');
        Assert::same(1, $by['Alex Example']['pickups_taken_by_other']);
        Assert::same(1, $by['Casey Example']['pickups_taken_by_other']);
        Assert::same(false, $by['Alex Example']['possible_voicemail_pickup'], 'normal blast behaviour is not flagged');
        $sum = $app->handle(Fixture::request('/api/v1/summary', $q + ['areas' => '70'], $token))->body['data'];
        Assert::same(-14400, $sum['attribution']['participant_clock_offset_seconds']);
    },
    'when YAP logs nothing after the dial: conference join = answered, caller alone = not connected, no record = unknown' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Admin', true, [], true, Client::ALL_SCOPES, []);
        $q = ['start_date' => '2026-06-13', 'end_date' => '2026-06-13', 'timezone' => 'UTC', 'areas' => '80'];
        $m = $app->handle(Fixture::request('/api/v1/summary', $q, $token))->body['data']['metrics'];
        Assert::same(3, $m['volunteer_routed_calls']['value']);
        Assert::same(1, $m['answered_calls']['value'], 'volunteer line in the conference');
        Assert::same(1, $m['answered_from_conference_record']['value']);
        Assert::same(1, $m['no_volunteer_response_logged_calls']['value'], 'caller alone in the conference');
        Assert::same(1, $m['routed_outcome_not_recorded_calls']['value'], 'no conference record at all');
        Assert::same(1, $m['routed_not_answered_calls']['value'], 'the caller who was never connected is not answered');
        Assert::same(50.0, $m['answer_rate']['value'], '1 answered of 2 with a known result');
        $calls = $app->handle(Fixture::request('/api/v1/calls', $q + ['sort' => 'asc'], $token))->body['data']['calls'];
        Assert::same('answered', $calls[0]['outcome']);
        Assert::same('Alex Example', $calls[0]['answered_by']['name'], 'only one number was dialed');
        Assert::same('no_volunteer_response_logged', $calls[1]['outcome']);
        Assert::same('outcome_not_recorded', $calls[2]['outcome']);
        $d = $app->handle(Fixture::request('/api/v1/volunteer-summary', $q, $token))->body['data'];
        $alex = array_values(array_filter($d['volunteers'], static fn ($v) => ($v['name'] ?? '') === 'Alex Example'))[0];
        Assert::same(3, $alex['routed_attempts']);
        Assert::same(1, $alex['answered_calls']);
        Assert::same(2, $alex['attempts_outcome_not_recorded'], 'not held against the volunteer');
        Assert::same(100.0, $alex['answer_rate']);
    },
    'text messages logged with non-SMS event names are not counted as calls' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Region', true, [], true, [Client::SCOPE_SUMMARY], []);
        $m = $app->handle(Fixture::request('/api/v1/summary', ['start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'timezone' => 'UTC'], $token))->body['data']['metrics'];
        Assert::same(8, $m['total_calls']['value']);
    },
    'a decline after someone else accepted is not a silent decline' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Admin', true, [], true, Client::ALL_SCOPES, []);
        $d = $app->handle(Fixture::request('/api/v1/volunteer-summary', ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'timezone' => 'UTC', 'areas' => '70'], $token))->body['data'];
        $alex = array_values(array_filter($d['volunteers'], static fn ($v) => ($v['name'] ?? '') === 'Alex Example'))[0];
        Assert::same(0, $alex['declined_without_key']);
        Assert::same(1, $alex['rejected_attempts']);
    },
];

