<?php

declare(strict_types=1);

namespace FakeYap;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A deterministic, realistic stretch of helpline activity for a fictional Region, and the figures the
 * reporting service must produce for it.
 *
 * Each call is generated as the rows YAP 4.5.2 writes for one of the call flows observed in production
 * (blast to several volunteers, voicemail, caller leaving while phones ring, forwarding to an outside
 * helpline, calls where YAP logged nothing after dialing, automated meeting / JFT / SPAD lookups,
 * text messages...). Alongside the rows, each call carries its expected contribution to every reported
 * figure, written down from the definitions in webservice docs/DATA-MAPPING.md, NOT computed by the
 * service's code. expected() sums those contributions for any Area filter and time window, so the
 * service is checked against an independent oracle.
 *
 * All names and numbers are fictional (NANP 555-01xx).
 */
final class Scenario
{
    public const REGION = 1000;
    public const OTHER_REGION = 2000;

    /** BMLT service bodies: id => [name, parent, type]. */
    public const SERVICE_BODIES = [
        1000 => ['Example Region of NA', 0, 'RS'],
        1001 => ['Riverside Area', 1000, 'AS'],
        1002 => ['Hillcrest Area', 1000, 'AS'],
        1003 => ['Lakeshore Area', 1000, 'AS'],
        1004 => ['Valley Area', 1000, 'AS'],
        2000 => ['Neighbouring Region', 0, 'RS'],
        2001 => ['Mountain Area', 2000, 'AS'],
    ];

    /** Areas that take helpline calls. 1003 forwards half of its calls to an outside helpline number. */
    public const CALL_AREAS = [1001, 1002, 1003, 1004, 2001];

    public const MAIN_LINE = '+15555550100';

    /** Area helpline numbers (YOUR lines, never callers): usable in attribution.line_mapping. */
    public const LINES = [1001 => '+15555550101', 1002 => '+15555550102', 1003 => '+15555550103', 1004 => '+15555550104', 2001 => '+15555550105'];

    /** Volunteers on each Area's list: name => E.164 number. */
    public const VOLUNTEERS = [
        1001 => ['Alex Riverside' => '+15555550111', 'Blair Riverside' => '+15555550112', 'Casey Riverside' => '+15555550113'],
        1002 => ['Drew Hillcrest' => '+15555550121', 'Emery Hillcrest' => '+15555550122', 'Finley Hillcrest' => '+15555550123'],
        1003 => ['Gray Lakeshore' => '+15555550131', 'Harper Lakeshore' => '+15555550132'],
        1004 => ['Indy Valley' => '+15555550141', 'Jordan Valley' => '+15555550142'],
        2001 => ['Kai Mountain' => '+15555550151', 'Lane Mountain' => '+15555550152'],
    ];

    /** Group volunteers (YAP_GROUP_VOLUNTEERS_V2), dialed together with the Area's own list. */
    public const GROUP_VOLUNTEERS = [1002 => ['Quinn Hillcrest Group' => '+15555550124']];

    /** A volunteer whose list entry has no name: reported with the last four digits only. */
    public const UNNAMED = [1003 => '+15555550133'];

    /** Every figure the oracle predicts (summary metric names). */
    public const SUMMARY_KEYS = [
        'total_calls', 'volunteer_requested_calls', 'volunteer_routed_calls', 'answered_calls', 'routed_not_answered_calls',
        'routed_outcome_not_recorded_calls', 'no_volunteer_response_logged_calls', 'answered_from_conference_record',
        'voicemail_calls', 'volunteer_calls_to_voicemail', 'abandoned_volunteer_calls', 'forwarded_to_helpline_calls',
        'ended_before_selection_calls', 'total_call_seconds', 'calls_with_known_duration', 'total_conversation_seconds',
        'meeting_lookup_calls', 'meeting_lookup_actions', 'jft_calls', 'jft_voice_plays', 'spad_calls', 'spad_voice_plays',
        // attempt level (counted by the attempt event's own time and service body)
        'volunteer_dial_attempts', 'attempts_not_answered', 'attempts_rejected', 'attempts_busy', 'attempts_bad_number',
        // message level
        'sms_meeting_lookups', 'sms_volunteer_requests', 'jft_sms', 'spad_sms',
    ];

    /** Flows of calls that ask for a volunteer, with their weight. */
    private const VOLUNTEER_FLOWS = [
        'answered_blast' => 30, 'answered_single' => 10, 'answered_short' => 4, 'voicemail' => 12, 'voicemail_pickup' => 4,
        'caller_left_ringing' => 10, 'no_response_logged' => 8, 'outcome_not_recorded' => 5, 'answered_conference_record' => 8,
        'force_dialed_busy_bad' => 3, 'not_routed' => 2,
    ];

    private DateTimeZone $tz;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $calls = null;

    private int $counter = 0;

    public function __construct(
        public readonly string $endDate,
        public readonly int $days,
        public readonly int $seed = 1,
        public readonly string $timezone = 'America/New_York',
        public readonly ?int $now = null,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1 || $days < 1 || $days > 800) {
            throw new \InvalidArgumentException('Give an end date (YYYY-MM-DD) and 1-800 days.');
        }
        $this->tz = new DateTimeZone($timezone);
    }

    public function startTs(): int
    {
        return (new DateTimeImmutable($this->endDate . ' 00:00:00', $this->tz))->modify('-' . ($this->days - 1) . ' days')->getTimestamp();
    }

    /** End of the scenario: midnight after endDate, or "now" minus ten minutes if that is earlier. */
    public function endTs(): int
    {
        $end = (new DateTimeImmutable($this->endDate . ' 00:00:00', $this->tz))->modify('+1 day')->getTimestamp();
        return $this->now !== null ? min($end, $this->now - 600) : $end;
    }

    /** @return array<int, array<string, mixed>> */
    public function calls(): array
    {
        if ($this->calls !== null) {
            return $this->calls;
        }
        mt_srand($this->seed, MT_RAND_MT19937);
        $this->counter = 0;
        $calls = [];

        // Volunteer requests: one timeline for the whole installation, never overlapping, so outcome events that
        // YAP did not link to a call cannot be matched to the wrong one (they are matched by number and time).
        $areaWeights = [1001 => 30, 1002 => 25, 1003 => 20, 1004 => 15, 2001 => 10];
        $cursor = $this->startTs() + mt_rand(600, 2400);
        while (true) {
            $sb = $this->pick($areaWeights);
            $flow = $sb === 1003 && mt_rand(0, 1) === 1 ? 'forwarded' : $this->pick(self::VOLUNTEER_FLOWS);
            $call = $this->volunteerCall($flow, $sb, $cursor);
            if ($call['end'] >= $this->endTs()) {
                break;
            }
            $calls[] = $call;
            $cursor = $call['end'] + mt_rand(600, 6600);
        }

        // Automated calls and text messages: at random times, overlapping the volunteer calls, so record ids are
        // not in start-time order (records are written when a call ends).
        for ($day = 0; $day < $this->days; $day++) {
            $dayStart = (new DateTimeImmutable('@' . $this->startTs()))->setTimezone($this->tz)->modify('+' . $day . ' days')->getTimestamp();
            $plan = [
                'meeting_search' => mt_rand(1, 4), 'meeting_search_attributed' => mt_rand(0, 5) === 0 ? 1 : 0,
                'jft' => mt_rand(0, 2), 'spad' => mt_rand(0, 1), 'menu_only' => mt_rand(0, 2), 'dialback' => mt_rand(0, 9) === 0 ? 1 : 0,
                'sms_meeting' => mt_rand(0, 3), 'sms_volunteer' => mt_rand(0, 1), 'sms_jft' => mt_rand(0, 2), 'sms_spad' => mt_rand(0, 1),
                'sms_blackholed' => mt_rand(0, 9) === 0 ? 1 : 0,
            ];
            foreach ($plan as $flow => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $t = $dayStart + mt_rand(0, 86399 - 1800);
                    $call = $this->automatedCall($flow, $t);
                    if ($call['end'] < $this->endTs()) {
                        $calls[] = $call;
                    }
                }
            }
        }
        usort($calls, static fn (array $a, array $b): int => [$a['start'], $a['sid']] <=> [$b['start'], $b['sid']]);
        return $this->calls = $calls;
    }

    public function write(YapWriter $w): int
    {
        foreach ($this->calls() as $call) {
            foreach ($call['rows'] as $row) {
                $method = array_shift($row);
                $w->{$method}(...$row);
            }
        }
        foreach (self::VOLUNTEERS as $sb => $list) {
            $entries = [];
            foreach ($list as $name => $number) {
                $entries[] = self::volunteerData($name, $number);
            }
            if (isset(self::UNNAMED[$sb])) {
                $entries[] = self::volunteerData('', self::UNNAMED[$sb]);
            }
            $w->volunteers($sb, $entries);
        }
        foreach (self::GROUP_VOLUNTEERS as $sb => $list) {
            $entries = [];
            foreach ($list as $name => $number) {
                $entries[] = self::volunteerData($name, $number);
            }
            $w->volunteers($sb, $entries, '_YAP_GROUP_VOLUNTEERS_V2_', 77);
        }
        // A deleted list (App\Constants\Status::DELETED = 1) naming a current volunteer differently: must be ignored.
        $w->volunteers(1001, [self::volunteerData('Deleted Name', self::VOLUNTEERS[1001]['Alex Riverside'])], '_YAP_VOLUNTEERS_V2_', 99, 1);
        $w->canaries();
        return $w->flush();
    }

    /**
     * Expected summary figures.
     *
     * @param int[]|null $areas service bodies (null = every service body)
     * @return array<string, int|float|null>
     */
    public function expected(?array $areas, bool $unassigned, int $from, int $to): array
    {
        $allows = static fn (?int $sb): bool => $sb === null ? $unassigned : ($areas === null || in_array($sb, $areas, true));
        $out = array_fill_keys(self::SUMMARY_KEYS, 0);
        foreach ($this->calls() as $c) {
            if ($c['call'] !== null && $c['start'] >= $from && $c['start'] < $to && $allows($c['sb'])) {
                foreach ($c['call'] as $k => $v) {
                    $out[$k] += $v;
                }
            }
            foreach ($c['interactions'] as [$key, $t, $sb]) {
                if ($t >= $from && $t < $to && $allows($sb)) {
                    $out[$key]++;
                }
            }
            foreach ($c['attempts'] as $a) {
                if ($a['t'] >= $from && $a['t'] < $to && $allows($a['sb'])) {
                    $key = self::ATTEMPT_METRIC[$a['name']] ?? null;
                    if ($key !== null) {
                        $out[$key]++;
                    }
                }
            }
        }
        // Calls with at least one interaction of a kind (an automated call can search twice).
        foreach (['meeting_lookup_calls' => 'meeting_lookup_actions', 'jft_calls' => 'jft_voice_plays', 'spad_calls' => 'spad_voice_plays'] as $calls => $actions) {
            $out[$calls] = 0;
            foreach ($this->calls() as $c) {
                foreach ($c['interactions'] as [$key, $t, $sb]) {
                    if ($key === $actions && $t >= $from && $t < $to && $allows($sb)) {
                        $out[$calls]++;
                        break;
                    }
                }
            }
        }
        $known = $out['volunteer_routed_calls'] - $out['routed_outcome_not_recorded_calls'];
        $out['answer_rate'] = $known > 0 ? round($out['answered_calls'] * 100 / $known, 1) : null;
        return $out;
    }

    /**
     * Expected per-volunteer figures, keyed by the name on the volunteer list (number hint for unnamed).
     *
     * @param int[]|null $areas
     * @return array<string, array<string, int>>
     */
    public function expectedVolunteers(?array $areas, bool $unassigned, int $from, int $to): array
    {
        $allows = static fn (?int $sb): bool => $sb === null ? $unassigned : ($areas === null || in_array($sb, $areas, true));
        $names = self::directory();
        $blank = ['routed_attempts' => 0, 'picked_up' => 0, 'answered_calls' => 0, 'total_conversation_seconds' => 0,
            'not_answered_attempts' => 0, 'rejected_attempts' => 0, 'busy_attempts' => 0, 'bad_number_attempts' => 0,
            'attempts_outcome_not_recorded' => 0, 'pickups_taken_by_other' => 0, 'declined_without_key' => 0];
        $out = [];
        $key = static fn (string $number): string => $names[self::digits($number)] ?? ('…' . substr(self::digits($number), -4));
        foreach ($this->calls() as $c) {
            foreach ($c['attempts'] as $a) {
                if ($a['t'] >= $from && $a['t'] < $to && $allows($a['sb'])) {
                    $field = self::ATTEMPT_VOLUNTEER_FIELD[$a['name']];
                    $out[$key($a['vol'])] ??= $blank;
                    $out[$key($a['vol'])][$field]++;
                }
            }
            if ($c['call'] === null || $c['start'] < $from || $c['start'] >= $to || !$allows($c['sb'])) {
                continue;
            }
            foreach ($c['volunteers'] as $number => $fields) {
                $out[$key($number)] ??= $blank;
                foreach ($fields as $f => $v) {
                    $out[$key($number)][$f] += $v;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** Local calendar day => expected trends figures (day granularity), for every day of the scenario. */
    public function expectedDaily(?array $areas, bool $unassigned): array
    {
        $out = [];
        $day = (new DateTimeImmutable('@' . $this->startTs()))->setTimezone($this->tz);
        for ($i = 0; $i < $this->days; $i++) {
            $from = $day->getTimestamp();
            $next = $day->modify('+1 day');
            $e = $this->expected($areas, $unassigned, $from, $next->getTimestamp());
            $out[$day->format('Y-m-d')] = [
                'total_calls' => $e['total_calls'], 'volunteer_routed_calls' => $e['volunteer_routed_calls'],
                'answered_calls' => $e['answered_calls'], 'voicemail_calls' => $e['voicemail_calls'],
                'meeting_lookup_actions' => $e['meeting_lookup_actions'], 'sms_meeting_lookups' => $e['sms_meeting_lookups'],
                'jft' => $e['jft_voice_plays'] + $e['jft_sms'], 'spad' => $e['spad_voice_plays'] + $e['spad_sms'],
            ];
            $day = $next;
        }
        return $out;
    }

    /** Normalised number => display name, as the volunteer lists define it. */
    public static function directory(): array
    {
        $out = [];
        foreach ([self::VOLUNTEERS, self::GROUP_VOLUNTEERS] as $lists) {
            foreach ($lists as $list) {
                foreach ($list as $name => $number) {
                    $out[self::digits($number)] = $name;
                }
            }
        }
        return $out;
    }

    /** BMLT GetServiceBodies answer (the shape a real root server returns: every value a string). */
    public static function bmltServiceBodies(): array
    {
        $out = [];
        foreach (self::SERVICE_BODIES as $id => [$name, $parent, $type]) {
            $out[] = ['id' => (string) $id, 'parent_id' => (string) $parent, 'name' => $name, 'description' => '', 'type' => $type,
                'url' => '', 'helpline' => self::LINES[$id] ?? '', 'world_id' => 'AR' . $id];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------------
    // Call flows. Times: t = when the caller's call started. Every row is ['method', ...writer arguments].
    // ------------------------------------------------------------------------------------------------------

    private const ATTEMPT_METRIC = [
        'VOLUNTEER_DIALED' => 'volunteer_dial_attempts', 'VOLUNTEER_NOANSWER' => 'attempts_not_answered',
        'VOLUNTEER_REJECTED' => 'attempts_rejected', 'VOLUNTEER_NUMBER_BUSY' => 'attempts_busy',
        'VOLUNTEER_NUMBER_BAD' => 'attempts_bad_number', 'VOLUNTEER_ANSWERED' => null,
    ];

    private const ATTEMPT_VOLUNTEER_FIELD = [
        'VOLUNTEER_DIALED' => 'routed_attempts', 'VOLUNTEER_NOANSWER' => 'not_answered_attempts',
        'VOLUNTEER_REJECTED' => 'rejected_attempts', 'VOLUNTEER_NUMBER_BUSY' => 'busy_attempts',
        'VOLUNTEER_NUMBER_BAD' => 'bad_number_attempts', 'VOLUNTEER_ANSWERED' => 'picked_up',
    ];

    /** @return array<string, mixed> */
    private function volunteerCall(string $flow, int $sb, int $t): array
    {
        $sid = $this->sid('CA');
        $conf = $this->sid('CF');
        $from = $this->caller();
        $line = mt_rand(0, 3) === 0 ? self::MAIN_LINE : self::LINES[$sb];
        $pool = self::VOLUNTEERS[$sb] + (self::GROUP_VOLUNTEERS[$sb] ?? []);
        if (isset(self::UNNAMED[$sb])) {
            $pool['…' . substr(self::UNNAMED[$sb], -4)] = self::UNNAMED[$sb];
        }
        $numbers = array_values($pool);
        $rows = [];
        $attempts = [];
        $vols = [];
        $c = self::emptyCall();
        $c['total_calls'] = 1;
        $c['volunteer_requested_calls'] = 1;

        if (mt_rand(0, 1) === 1) {
            $rows[] = ['event', $sid, 'STIR_VERSTAT_RECEIVED', $t, null, ['verstat' => 'TN-Validation-Passed-A']];
        }
        // VOLUNTEER_SEARCH: production logs it without a service body (the caller's location is not resolved yet).
        $searchSb = mt_rand(0, 2) === 0 ? $sb : null;
        $rows[] = ['event', $sid, $flow === 'force_dialed_busy_bad' ? 'VOLUNTEER_SEARCH_FORCE_DIALED' : 'VOLUNTEER_SEARCH', $t + 3, $flow === 'force_dialed_busy_bad' ? $sb : $searchSb,
            $flow === 'force_dialed_busy_bad' ? ['number' => self::LINES[$sb]] : ['gather' => 'Springfield', 'coordinates' => ['latitude' => 38.1, 'longitude' => -77.2]]];

        $dial = function (array $who, int $at) use (&$rows, &$attempts, $sid, $sb, &$vols): void {
            foreach ($who as $number) {
                // Event 5 stores the number as typed on the volunteer list (production: 10 digits).
                $rows[] = ['event', $sid, 'VOLUNTEER_DIALED', $at, $sb, ['to_number' => self::digits($number)]];
                $attempts[] = ['name' => 'VOLUNTEER_DIALED', 'vol' => $number, 't' => $at, 'sb' => $sb];
                $vols[$number] ??= [];
            }
        };
        $legEvent = function (string $leg, string $name, int $at, string $number, array $extra = []) use (&$rows, &$attempts, $sb): void {
            // Events 6, 7, 8, 26, 27 carry Twilio's Called parameter (E.164).
            $rows[] = ['event', $leg, $name, $at, $sb, $extra + ['to_number' => $number]];
            if (array_key_exists($name, self::ATTEMPT_METRIC)) {
                $attempts[] = ['name' => $name, 'vol' => $number, 't' => $at, 'sb' => $sb];
            }
        };
        $callerJoins = function (int $at) use (&$rows, $sid, $conf, $sb): void {
            $rows[] = ['participant', $conf, $sid, 'conf-' . $sid, 1, $at];
            $rows[] = ['event', $sid, 'CALLER_IN_CONFERENCE', $at, $sb, null];
        };

        switch ($flow) {
            case 'answered_blast':
            case 'answered_single':
            case 'answered_short':
            case 'voicemail_pickup':
                $callerJoins($t + 5);
                $who = $flow === 'answered_blast' ? $this->some($numbers, 2, count($numbers)) : $this->some($numbers, 1, 1);
                $dial($who, $t + 6);
                $legs = [];
                foreach ($who as $number) {
                    $legs[$number] = $this->sid('CA');
                }
                $pickedUp = $flow === 'answered_blast' ? $this->some($who, 1, count($who)) : $who;
                $at = $t + 9;
                foreach ($pickedUp as $number) {
                    $legEvent($legs[$number], 'VOLUNTEER_ANSWERED', $at, $number);
                    $rows[] = ['participant', $conf, $legs[$number], 'conf-' . $sid, 2, $at];
                    $at += mt_rand(1, 4);
                }
                foreach (array_diff($who, $pickedUp) as $number) {
                    $legEvent($legs[$number], 'VOLUNTEER_NOANSWER', $t + 36, $number);
                    $rows[] = ['participant', $conf, $legs[$number], 'conf-' . $sid, 2, $t + 36];
                }
                if ($flow === 'voicemail_pickup') {
                    // The phone was answered by a carrier voicemail: nothing pressed (digits ""), then YAP's voicemail.
                    $number = $pickedUp[0];
                    $legEvent($legs[$number], 'VOLUNTEER_REJECTED', $t + 26, $number, ['digits' => '']);
                    $rows[] = ['event', $sid, 'VOICEMAIL', $t + 40, $sb, ['url' => 'https://api.twilio.com/fake/Recordings/RE' . substr($sid, 2, 10), 'caller_number' => $from]];
                    $end = $t + 40 + mt_rand(20, 90);
                    $c['volunteer_routed_calls'] = 1;
                    $c['voicemail_calls'] = 1;
                    $c['volunteer_calls_to_voicemail'] = 1;
                    $c['routed_not_answered_calls'] = 1;
                    $vols[$number]['declined_without_key'] = 1;
                    break;
                }
                $accepter = $pickedUp[mt_rand(0, count($pickedUp) - 1)];
                $accept = $at + mt_rand(2, 6);
                $legEvent($legs[$accepter], 'VOLUNTEER_IN_CONFERENCE', $accept, $accepter);
                foreach ($pickedUp as $number) {
                    if ($number !== $accepter) {
                        // Production: phones still connected when another volunteer accepts are logged as declined, digits null.
                        $legEvent($legs[$number], 'VOLUNTEER_REJECTED', $accept + 1, $number, ['digits' => null]);
                        $vols[$number]['pickups_taken_by_other'] = 1;
                    }
                }
                $talk = match ($flow) {
                    'answered_short' => mt_rand(1, 4),
                    default => mt_rand(0, 9) === 0 ? mt_rand(3600, 4800) : mt_rand(60, 900),
                };
                $rows[] = ['event', $legs[$accepter], 'VOLUNTEER_HUP', $accept + $talk, $sb, null];
                $rows[] = ['event', $sid, 'CALLER_HUP', $accept + $talk + 1, $sb, null];
                $end = $accept + $talk + 2;
                $c['volunteer_routed_calls'] = 1;
                $c['answered_calls'] = 1;
                $c['total_conversation_seconds'] = $talk;
                $vols[$accepter]['answered_calls'] = 1;
                $vols[$accepter]['total_conversation_seconds'] = $talk;
                break;

            case 'voicemail':
                $callerJoins($t + 5);
                $who = $this->some($numbers, 1, count($numbers));
                $dial($who, $t + 6);
                foreach ($who as $number) {
                    $leg = $this->sid('CA');
                    $legEvent($leg, 'VOLUNTEER_NOANSWER', $t + 36, $number);
                    $rows[] = ['participant', $conf, $leg, 'conf-' . $sid, 2, $t + 36];
                }
                $rows[] = ['event', $sid, 'VOICEMAIL', $t + 40, $sb, ['url' => 'https://api.twilio.com/fake/Recordings/RE' . substr($sid, 2, 10), 'caller_number' => $from]];
                $end = $t + 40 + mt_rand(20, 120);
                $c['volunteer_routed_calls'] = 1;
                $c['voicemail_calls'] = 1;
                $c['volunteer_calls_to_voicemail'] = 1;
                $c['routed_not_answered_calls'] = 1;
                break;

            case 'caller_left_ringing':
                $callerJoins($t + 5);
                $who = $this->some($numbers, 1, count($numbers));
                $dial($who, $t + 6);
                $leg = $this->sid('CA');
                $legEvent($leg, 'VOLUNTEER_NOANSWER', $t + 20, $who[0]);
                $rows[] = ['participant', $conf, $leg, 'conf-' . $sid, 2, $t + 20];
                $rows[] = ['event', $sid, 'CALLER_HUP', $t + 24, $sb, null];
                $end = $t + 25;
                $c['volunteer_routed_calls'] = 1;
                $c['routed_not_answered_calls'] = 1;
                $c['abandoned_volunteer_calls'] = 1;
                break;

            case 'no_response_logged':
                // Production since mid-September: the dial and the caller's hang-up, nothing about any phone.
                $callerJoins($t + 5);
                $who = $this->some($numbers, 1, count($numbers));
                $dial($who, $t + 6);
                $rows[] = ['event', $sid, 'CALLER_HUP', $t + mt_rand(40, 400), $sb, null];
                $end = $t + 420;
                $c['volunteer_routed_calls'] = 1;
                $c['no_volunteer_response_logged_calls'] = 1;
                $c['routed_not_answered_calls'] = 1;
                foreach ($who as $number) {
                    $vols[$number]['attempts_outcome_not_recorded'] = 1;
                }
                break;

            case 'outcome_not_recorded':
                // Dialed, then nothing at all: not even the caller in the conference.
                $who = $this->some($numbers, 1, count($numbers));
                $dial($who, $t + 6);
                $rows[] = ['event', $sid, 'CALLER_HUP', $t + mt_rand(40, 200), $sb, null];
                $end = $t + 210;
                $c['volunteer_routed_calls'] = 1;
                $c['routed_outcome_not_recorded_calls'] = 1;
                foreach ($who as $number) {
                    $vols[$number]['attempts_outcome_not_recorded'] = 1;
                }
                break;

            case 'answered_conference_record':
                // No volunteer events, but the volunteer's line joined the caller's conference: answered.
                $callerJoins($t + 5);
                $number = $numbers[mt_rand(0, count($numbers) - 1)];
                $dial([$number], $t + 6);
                $join = $t + mt_rand(12, 30);
                $rows[] = ['participant', $conf, $this->sid('CA'), 'conf-' . $sid, 2, $join];
                $talk = mt_rand(60, 1200);
                $rows[] = ['event', $sid, 'CALLER_HUP', $join + $talk, $sb, null];
                $end = $join + $talk + 2;
                $c['volunteer_routed_calls'] = 1;
                $c['answered_calls'] = 1;
                $c['answered_from_conference_record'] = 1;
                $c['total_conversation_seconds'] = $talk;
                $vols[$number]['answered_calls'] = 1;
                $vols[$number]['total_conversation_seconds'] = $talk;
                break;

            case 'force_dialed_busy_bad':
                $who = [$numbers[0], $numbers[1]];
                $callerJoins($t + 4);
                $dial([$who[0]], $t + 5);
                $leg = $this->sid('CA');
                $legEvent($leg, 'VOLUNTEER_NUMBER_BUSY', $t + 7, $who[0]);
                $rows[] = ['participant', $conf, $leg, 'conf-' . $sid, 2, $t + 7];
                $dial([$who[1]], $t + 8);
                $leg = $this->sid('CA');
                $legEvent($leg, 'VOLUNTEER_NUMBER_BAD', $t + 9, $who[1], ['error' => 'Invalid number']);
                $rows[] = ['participant', $conf, $leg, 'conf-' . $sid, 2, $t + 9];
                $rows[] = ['event', $sid, 'CALLER_HUP', $t + 50, $sb, null];
                $end = $t + 51;
                $c['volunteer_routed_calls'] = 1;
                $c['routed_not_answered_calls'] = 1;
                $c['abandoned_volunteer_calls'] = 1;
                break;

            case 'not_routed':
                // Asked for a volunteer; nobody was on shift and no voicemail: YAP dialed nobody.
                $rows[] = ['event', $sid, 'CALLER_HUP', $t + 30, $sb, null];
                $end = $t + 31;
                $c['abandoned_volunteer_calls'] = 1;
                break;

            case 'forwarded':
                // Call handling "helpline": event 15 instead of any dialing (verified in production: 1 then 15).
                $rows[] = ['event', $sid, 'HELPLINE_ROUTE', $t + 4, $sb, ['helpline_number' => '5555550199', 'extension' => 'w']];
                $end = $t + mt_rand(60, 900);
                $c['forwarded_to_helpline_calls'] = 1;
                break;

            default:
                throw new \LogicException($flow);
        }

        $rows[] = ['record', $sid, $t, $end, $from, $line, $end - $t, $this->voicePayload($sid, $from, $line, $end - $t)];
        $c['total_call_seconds'] = $end - $t;
        $c['calls_with_known_duration'] = 1;
        // Every flow logs a routing event or a hang-up with the Area's service body, so the call belongs to the
        // Area under the default "routing" policy (last routing event with one, else the first event with one).
        return ['flow' => $flow, 'sid' => $sid, 'sb' => $sb, 'line' => $line, 'start' => $t, 'end' => $end,
            'rows' => $rows, 'call' => $c, 'interactions' => [], 'attempts' => $attempts, 'volunteers' => $vols];
    }

    /** @return array<string, mixed> */
    private function automatedCall(string $flow, int $t): array
    {
        $sms = str_starts_with($flow, 'sms_');
        $sid = $this->sid($sms ? 'SM' : 'CA');
        $from = $this->caller();
        $line = mt_rand(0, 2) === 0 ? self::MAIN_LINE : self::LINES[self::CALL_AREAS[mt_rand(0, 4)]];
        $rows = [];
        $interactions = [];
        $c = self::emptyCall();
        $sb = null;

        if ($sms) {
            // CallFlowController::smsGateway(): a records row (type 2) for every inbound text, then the events (type 2).
            $rows[] = ['record', $sid, $t, $t, $from, $line, 0, ['ToCountry' => 'US', 'SmsMessageSid' => $sid, 'NumMedia' => '0', 'SmsSid' => $sid,
                'SmsStatus' => 'received', 'Body' => 'Springfield', 'To' => $line, 'From' => $from, 'ApiVersion' => '2010-04-01', 'AccountSid' => 'ACfake'], 2];
            $event = match ($flow) {
                'sms_meeting' => 'MEETING_SEARCH_SMS',
                'sms_volunteer' => 'VOLUNTEER_SEARCH_SMS',
                'sms_jft' => 'JFT_LOOKUP_SMS',
                'sms_spad' => 'SPAD_LOOKUP_SMS',
                'sms_blackholed' => 'SMS_BLACKHOLED',
            };
            // Verified in production: every text-message event is stored without a service body.
            $rows[] = ['event', $sid, $event, $t, null, null, 2];
            if ($flow === 'sms_meeting') {
                $rows[] = ['event', $sid, 'MEETING_SEARCH_LOCATION_GATHERED', $t, null, ['gather' => 'Springfield', 'coordinates' => ['latitude' => 38.1, 'longitude' => -77.2]], 2];
            }
            $key = ['sms_meeting' => 'sms_meeting_lookups', 'sms_volunteer' => 'sms_volunteer_requests', 'sms_jft' => 'jft_sms', 'sms_spad' => 'spad_sms'][$flow] ?? null;
            if ($key !== null) {
                $interactions[] = [$key, $t, null];
            }
            // A text message is not a call.
            return ['flow' => $flow, 'sid' => $sid, 'sb' => null, 'line' => $line, 'start' => $t, 'end' => $t, 'rows' => $rows, 'call' => null,
                'interactions' => $interactions, 'attempts' => [], 'volunteers' => []];
        }

        if (mt_rand(0, 1) === 1) {
            $rows[] = ['event', $sid, 'STIR_VERSTAT_RECEIVED', $t, null, ['verstat' => 'TN-Validation-Passed-A']];
        }
        $at = $t + mt_rand(10, 40);
        switch ($flow) {
            case 'meeting_search':
            case 'meeting_search_attributed':
                // Production: MEETING_SEARCH and the gathered location carry no service body. Older builds set one.
                $sb = $flow === 'meeting_search_attributed' ? 1001 : null;
                $searches = mt_rand(0, 3) === 0 ? 2 : 1;
                for ($i = 0; $i < $searches; $i++) {
                    $rows[] = ['event', $sid, 'MEETING_SEARCH', $at, $sb, null];
                    $interactions[] = ['meeting_lookup_actions', $at, $sb];
                    if (mt_rand(0, 2) === 0) {
                        $rows[] = ['event', $sid, 'MEETING_SEARCH_LOCATION_GATHERED', $at + 5, null, ['gather' => '22801', 'coordinates' => ['latitude' => 38.1, 'longitude' => -77.2]]];
                    }
                    $at += mt_rand(30, 90);
                }
                break;
            case 'jft':
                $rows[] = ['event', $sid, 'JFT_LOOKUP', $at, null, null];
                $interactions[] = ['jft_voice_plays', $at, null];
                $at += 180;
                break;
            case 'spad':
                $rows[] = ['event', $sid, 'SPAD_LOOKUP', $at, null, null];
                $interactions[] = ['spad_voice_plays', $at, null];
                $at += 180;
                break;
            case 'dialback':
                $rows[] = ['event', $sid, 'DIALBACK', $at, null, ['dialback_number' => 'hidden']];
                $at += 60;
                break;
            case 'menu_only':
                $c['ended_before_selection_calls'] = 1;
                $at = $t + mt_rand(5, 40);
                break;
            default:
                throw new \LogicException($flow);
        }
        $end = $at + mt_rand(1, 20);
        $rows[] = ['record', $sid, $t, $end, $from, $line, $end - $t, $this->voicePayload($sid, $from, $line, $end - $t)];
        $c['total_calls'] = 1;
        $c['total_call_seconds'] = $end - $t;
        $c['calls_with_known_duration'] = 1;
        return ['flow' => $flow, 'sid' => $sid, 'sb' => $sb, 'line' => $line, 'start' => $t, 'end' => $end, 'rows' => $rows, 'call' => $c,
            'interactions' => $interactions, 'attempts' => [], 'volunteers' => []];
    }

    /** @return array<string, int> */
    private static function emptyCall(): array
    {
        $c = array_fill_keys(self::SUMMARY_KEYS, 0);
        foreach (['volunteer_dial_attempts', 'attempts_not_answered', 'attempts_rejected', 'attempts_busy', 'attempts_bad_number',
            'sms_meeting_lookups', 'sms_volunteer_requests', 'jft_sms', 'spad_sms', 'meeting_lookup_calls', 'meeting_lookup_actions',
            'jft_calls', 'jft_voice_plays', 'spad_calls', 'spad_voice_plays'] as $k) {
            unset($c[$k]);
        }
        return $c;
    }

    /** @return array<string, mixed> Twilio's status callback request, as YAP stores it in records.payload. */
    private function voicePayload(string $sid, string $from, string $to, int $duration): array
    {
        return ['Called' => $to, 'ToState' => 'VA', 'CallerCountry' => 'US', 'Direction' => 'inbound', 'Timestamp' => gmdate(DATE_RFC2822),
            'CallbackSource' => 'call-progress-events', 'CallerState' => 'VA', 'ToZip' => '', 'SequenceNumber' => '0', 'CallSid' => $sid,
            'To' => $to, 'CallerZip' => '', 'ToCountry' => 'US', 'CalledZip' => '', 'ApiVersion' => '2010-04-01', 'CalledCity' => '',
            'CallStatus' => 'completed', 'Duration' => (string) max(1, intdiv($duration, 60)), 'From' => $from, 'CallDuration' => (string) $duration,
            'AccountSid' => 'ACfake00000000000000000000000000', 'CalledCountry' => 'US', 'CallerCity' => '', 'ToCity' => '', 'FromCountry' => 'US',
            'Caller' => $from, 'FromCity' => '', 'CalledState' => 'VA', 'FromZip' => '', 'FromState' => 'VA'];
    }

    /** @return array<string, mixed> App\Structures\VolunteerData */
    private static function volunteerData(string $name, string $number): array
    {
        $shifts = [];
        for ($d = 1; $d <= 7; $d++) {
            $shifts[] = ['day' => $d, 'tz' => 'America/New_York', 'start_time' => '12:00 AM', 'end_time' => '11:59 PM'];
        }
        return ['volunteer_name' => $name, 'volunteer_phone_number' => $number, 'volunteer_gender' => 0, 'volunteer_responder' => 0,
            'volunteer_languages' => ['en'], 'volunteer_notes' => '', 'volunteer_enabled' => true,
            'volunteer_shift_schedule' => base64_encode((string) json_encode($shifts))];
    }

    public static function digits(string $number): string
    {
        $d = preg_replace('/\D+/', '', $number) ?? '';
        return strlen($d) === 11 && $d[0] === '1' ? substr($d, 1) : $d;
    }

    /** Callers use 555-0150 to 555-0199. */
    private function caller(): string
    {
        return '+1555555' . sprintf('%04d', mt_rand(150, 199));
    }

    private function sid(string $prefix): string
    {
        $this->counter++;
        return $prefix . md5('fake-yap|' . $this->seed . '|' . $this->counter);
    }

    /**
     * @template T
     * @param array<T, int> $weights
     * @return T
     */
    private function pick(array $weights): mixed
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $k => $w) {
            $r -= $w;
            if ($r <= 0) {
                return $k;
            }
        }
        return array_key_last($weights);
    }

    /**
     * @param string[] $items
     * @return string[] between $min and $max distinct items, in list order
     */
    private function some(array $items, int $min, int $max): array
    {
        $n = mt_rand($min, max($min, $max));
        $keys = array_keys($items);
        shuffle($keys);
        $keys = array_slice($keys, 0, $n);
        sort($keys);
        return array_values(array_map(static fn ($k) => $items[$k], $keys));
    }
}
