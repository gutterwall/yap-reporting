<?php

declare(strict_types=1);

use YapReporting\Reporting\SessionBuilder;

$builder = static fn (int $threshold = 5): SessionBuilder => new SessionBuilder($threshold, 1, 2, 'routing', []);
$strict = static fn (int $threshold = 5): SessionBuilder => new SessionBuilder($threshold, 1, 2, 'routing', [], 'min_seconds');
$ev = static fn (string $cs, int $t, string $name, ?int $sb = 10, ?string $vol = null): array =>
    ['cs' => $cs, 't' => $t, 'eid' => crc32($name) % 1000, 'name' => $name, 'sb' => $sb, 'vol' => $vol];

/** A routed call where the volunteer joins at t=100 and the caller hangs up at 100 + $seconds. */
$connected = static function (int $seconds) use ($ev): array {
    return [
        [$ev('C1', 0, 'VOLUNTEER_SEARCH'), $ev('C1', 5, 'VOLUNTEER_DIALED', 10, '5555550171'), $ev('V1', 100, 'VOLUNTEER_IN_CONFERENCE', 10, '5555550171'), $ev('C1', 100 + $seconds, 'CALLER_HUP')],
        [['cs' => 'C1', 'conf' => 'F1', 'role' => 1, 't' => 1], ['cs' => 'V1', 'conf' => 'F1', 'role' => 2, 't' => 100]],
        [],
    ];
};

return [
    'multiple routing attempts count as one call' => function () use ($builder, $ev): void {
        $events = [
            $ev('C1', 0, 'VOLUNTEER_SEARCH'),
            $ev('C1', 5, 'VOLUNTEER_DIALED', 10, '5555550171'),
            $ev('V1', 25, 'VOLUNTEER_NOANSWER', 10, '5555550171'),
            $ev('C1', 26, 'VOLUNTEER_DIALED', 10, '5555550172'),
            $ev('V2', 40, 'VOLUNTEER_REJECTED', 10, '5555550172'),
            $ev('C1', 41, 'VOLUNTEER_DIALED', 10, '5555550173'),
            $ev('V3', 50, 'VOLUNTEER_IN_CONFERENCE', 10, '5555550173'),
            $ev('V3', 200, 'VOLUNTEER_HUP'),
        ];
        $participants = [
            ['cs' => 'C1', 'conf' => 'F1', 'role' => 1, 't' => 1],
            ['cs' => 'V3', 'conf' => 'F1', 'role' => 2, 't' => 50],
        ];
        $out = $builder()->build($events, $participants, [], 0, 1000);
        Assert::same(1, count($out['sessions']), 'unique calls');
        $s = $out['sessions'][0];
        Assert::same(3, $s['attempts']);
        Assert::true($s['answered']);
        Assert::same(150, $s['conversation']);
        Assert::same('5555550173', $s['answered_by']);
        Assert::same(2, $out['diagnostics']['orphan_volunteer_legs'], 'unanswered legs are not calls');
        Assert::same(5, count($out['attempts']), 'attempt events are retained for attempt-level stats');
    },
    'default (YAP) rule: any accepted connection is answered, however short' => function () use ($builder, $connected): void {
        [$e, $p, $r] = $connected(2);
        $s = $builder()->build($e, $p, $r, 0, 1000)['sessions'][0];
        Assert::true($s['answered']);
        Assert::same(2, $s['conversation']);
    },
    'min_seconds rule: exactly 5 seconds is NOT answered' => function () use ($strict, $connected): void {
        [$e, $p, $r] = $connected(5);
        $s = $strict()->build($e, $p, $r, 0, 1000)['sessions'][0];
        Assert::true(!$s['answered']);
        Assert::same('connected_short', $s['answer_status']);
    },
    '6 seconds is answered' => function () use ($builder, $connected): void {
        [$e, $p, $r] = $connected(6);
        $s = $builder()->build($e, $p, $r, 0, 1000)['sessions'][0];
        Assert::true($s['answered']);
        Assert::same(6, $s['conversation']);
    },
    'min_seconds threshold is configurable' => function () use ($strict, $connected): void {
        [$e, $p, $r] = $connected(20);
        Assert::true(!$strict(30)->build($e, $p, $r, 0, 1000)['sessions'][0]['answered']);
    },
    'accepted connection without a hang-up record is answered (YAP rule) but unverified under min_seconds' => function () use ($builder, $strict, $ev): void {
        $events = [$ev('C1', 0, 'VOLUNTEER_SEARCH'), $ev('C1', 5, 'VOLUNTEER_DIALED', 10, '5555550171'), $ev('V1', 30, 'VOLUNTEER_IN_CONFERENCE', 10, '5555550171')];
        $participants = [['cs' => 'C1', 'conf' => 'F1', 'role' => 1, 't' => 1], ['cs' => 'V1', 'conf' => 'F1', 'role' => 2, 't' => 30]];
        $s = $builder()->build($events, $participants, [], 0, 1000)['sessions'][0];
        Assert::true($s['answered']);
        Assert::same(null, $s['conversation']);
        $s = $strict()->build($events, $participants, [], 0, 1000)['sessions'][0];
        Assert::same('connected_unknown_duration', $s['answer_status']);
        Assert::true(!$s['answered']);
    },
    'volunteer picked up but declined is not answered' => function () use ($builder, $ev): void {
        $events = [$ev('C1', 0, 'VOLUNTEER_SEARCH'), $ev('C1', 5, 'VOLUNTEER_DIALED', 10, '1'), $ev('V1', 9, 'VOLUNTEER_ANSWERED', 10, '1'), $ev('V1', 12, 'VOLUNTEER_REJECTED', 10, '1'), $ev('C1', 40, 'CALLER_HUP')];
        $s = $builder()->build($events, [['cs' => 'C1', 'conf' => 'F1', 'role' => 1, 't' => 1]], [], 0, 1000)['sessions'][0];
        Assert::true(!$s['answered'], 'VOLUNTEER_ANSWERED alone (no accept / no connection) is not an answered call');
    },
    'ringing or volunteer answering after caller left is not answered' => function () use ($builder, $ev): void {
        $events = [
            $ev('C1', 0, 'VOLUNTEER_SEARCH'),
            $ev('C1', 5, 'VOLUNTEER_DIALED', 10, '5555550171'),
            $ev('C1', 20, 'CALLER_HUP'),
            $ev('V1', 25, 'VOLUNTEER_ANSWERED_BUT_CALLER_HUP', 10, '5555550171'),
        ];
        $s = $builder()->build($events, [['cs' => 'C1', 'conf' => 'F1', 'role' => 1, 't' => 1]], [], 0, 1000)['sessions'][0];
        Assert::true(!$s['answered']);
        Assert::same('not_answered', $s['outcome']);
    },
    'voicemail is an outcome, not an answer' => function () use ($builder, $ev): void {
        $events = [$ev('C1', 0, 'VOLUNTEER_SEARCH'), $ev('C1', 5, 'VOLUNTEER_DIALED', 10, '1'), $ev('C1', 60, 'VOICEMAIL')];
        $s = $builder()->build($events, [], [], 0, 1000)['sessions'][0];
        Assert::true(!$s['answered']);
        Assert::same('voicemail', $s['outcome']);
    },
    'menu-only call' => function () use ($builder): void {
        $records = [['cs' => 'C9', 'start' => 10, 'end' => 20, 'dur' => 10, 'line' => null, 'dir' => 'inbound']];
        $s = $builder()->build([], [], $records, 0, 1000)['sessions'][0];
        Assert::same('menu_only', $s['category']);
        Assert::same(10, $s['dur']);
    },
    'outbound records are legs, not calls' => function () use ($builder): void {
        $records = [['cs' => 'V9', 'start' => 10, 'end' => 20, 'dur' => 10, 'line' => null, 'dir' => 'outbound-api']];
        Assert::same(0, count($builder()->build([], [], $records, 0, 1000)['sessions']));
    },
    'calls starting outside the range are excluded' => function () use ($builder, $ev): void {
        $events = [$ev('C1', 50, 'MEETING_SEARCH', null)];
        Assert::same(0, count($builder()->build($events, [], [], 100, 200)['sessions']));
    },
    'routing attribution uses the last routing event' => function () use ($ev): void {
        $b = new SessionBuilder(5, 1, 2, 'routing', []);
        $events = [$ev('C1', 0, 'MEETING_SEARCH', 7), $ev('C1', 5, 'HELPLINE_ROUTE', 10), $ev('C1', 6, 'VOLUNTEER_DIALED', 12, '1')];
        $s = $b->build($events, [], [], 0, 1000)['sessions'][0];
        Assert::same(12, $s['sb']);
        Assert::same('routing_event', $s['sb_source']);
    },
    'line mapping attributes calls without service body' => function () use ($ev): void {
        $b = new SessionBuilder(5, 1, 2, 'routing', ['5555550100' => 33]);
        $records = [['cs' => 'C1', 'start' => 0, 'end' => 60, 'dur' => 60, 'line' => '5555550100', 'dir' => 'inbound']];
        $s = $b->build([$ev('C1', 10, 'JFT_LOOKUP', null)], [], $records, 0, 1000)['sessions'][0];
        Assert::same(33, $s['sb']);
        Assert::same(33, $s['interactions'][0]['sb']);
        Assert::same('line_mapping', $s['interactions'][0]['sb_source']);
    },
    'SMS events are not phone calls' => function () use ($builder, $ev): void {
        $out = $builder()->build([$ev('SM1', 10, 'MEETING_SEARCH_SMS')], [], [], 0, 1000);
        Assert::same(0, count($out['sessions']));
        Assert::same(1, count($out['sms']));
    },
    'a pick-up that joins the conference but never accepts is not an answer' => function () use ($builder, $ev): void {
        // Production YAP adds the volunteer leg to conference_participants at pick-up, before the key press.
        $events = [$ev('C1', 0, 'VOLUNTEER_SEARCH'), $ev('C1', 5, 'VOLUNTEER_DIALED', 10, '5555550171'), $ev('V1', 9, 'VOLUNTEER_ANSWERED', 10, '5555550171'), $ev('C1', 60, 'CALLER_HUP')];
        $participants = [['cs' => 'C1', 'conf' => 'F1', 'role' => 1, 't' => 1], ['cs' => 'V1', 'conf' => 'F1', 'role' => 2, 't' => 9]];
        $s = $builder()->build($events, $participants, [], 0, 1000)['sessions'][0];
        Assert::true(!$s['answered']);
        Assert::same([['vol' => '5555550171', 't' => 9]], $s['pickups']);
    },
    'participant clock offset is detected and rounded' => function (): void {
        $events = [
            ['cs' => 'C1', 't' => 1000, 'name' => 'CALLER_IN_CONFERENCE'],
            ['cs' => 'V1', 't' => 1010, 'name' => 'VOLUNTEER_ANSWERED'],
            ['cs' => 'V2', 't' => 1020, 'name' => 'VOLUNTEER_ANSWERED'],
        ];
        $participants = [
            ['cs' => 'C1', 'role' => 1, 't' => 1000 - 14400 + 1],
            ['cs' => 'V1', 'role' => 2, 't' => 1010 - 14400],
            ['cs' => 'V2', 'role' => 2, 't' => 1020 - 14400 - 1],
            ['cs' => 'V2', 'role' => 2, 't' => 1080 - 14400],
        ];
        Assert::same(-14400, \YapReporting\Reporting\DatasetLoader::participantClockOffset($events, $participants));
        Assert::same(0, \YapReporting\Reporting\DatasetLoader::participantClockOffset($events, [['cs' => 'C1', 'role' => 1, 't' => 1003], ['cs' => 'V1', 'role' => 2, 't' => 1012]]));
    },
];

