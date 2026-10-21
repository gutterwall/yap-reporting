<?php

declare(strict_types=1);

namespace YapReporting\Reporting;

use DateTimeImmutable;

/**
 * Computes report figures from a loaded dataset, restricted to an AreaFilter.
 *
 * Every metric is returned as:
 *   { value, available, level, [unit], [reason] }
 * where level is one of:
 *   call        — counts unique incoming calls (multi-leg calls count once)
 *   attempt     — counts individual volunteer dial attempts
 *   interaction — counts individual automated actions (a call can search for meetings twice)
 *   message     — counts individual inbound SMS events
 */
final class MetricEngine
{
    /** @param array<string, mixed> $dataset */
    public function __construct(
        private readonly array $dataset,
        private readonly AreaFilter $filter,
        private readonly Capabilities $caps,
        private readonly DateRange $range,
        private readonly string $weekStartsOn = 'sunday',
    ) {
    }

    // ------------------------------------------------------------------------------------
    // Building blocks
    // ------------------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> calls attributed to the selected areas */
    public function calls(): array
    {
        return array_values(array_filter(
            $this->dataset['sessions'],
            fn (array $s): bool => $this->filter->allows($s['sb'])
        ));
    }

    /** @return array<int, array<string, mixed>> automated voice interactions in selected areas */
    public function interactions(?string $name = null): array
    {
        $out = [];
        foreach ($this->dataset['sessions'] as $s) {
            foreach ($s['interactions'] as $i) {
                if (($name === null || $i['name'] === $name) && $this->filter->allows($i['sb'])) {
                    $i['root'] = $s['root'];
                    $out[] = $i;
                }
            }
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function sms(?string $name = null): array
    {
        return array_values(array_filter(
            $this->dataset['sms'],
            fn (array $e): bool => ($name === null || $e['name'] === $name) && $this->filter->allows($e['sb'])
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function attempts(): array
    {
        return array_values(array_filter(
            $this->dataset['attempts'],
            fn (array $e): bool => $this->filter->allows($e['sb'])
        ));
    }

    /** @return array<string, mixed> */
    public static function value(int|float|null $value, string $level, ?string $unit = null): array
    {
        $m = ['value' => $value, 'available' => true, 'level' => $level];
        if ($unit !== null) {
            $m['unit'] = $unit;
        }
        return $m;
    }

    /** @return array<string, mixed> */
    public static function unavailable(string $level, string $reason, ?string $unit = null): array
    {
        $m = ['value' => null, 'available' => false, 'level' => $level, 'reason' => $reason];
        if ($unit !== null) {
            $m['unit'] = $unit;
        }
        return $m;
    }

    /**
     * @param callable(): (int|float|null) $compute
     * @return array<string, mixed>
     */
    private function metric(string $requirement, string $level, callable $compute, ?string $unit = null): array
    {
        $reason = $this->caps->missing($requirement);
        return $reason === null ? self::value($compute(), $level, $unit) : self::unavailable($level, $reason, $unit);
    }

    public static function pct(int $num, int $den): ?float
    {
        return $den > 0 ? round($num * 100 / $den, 1) : null;
    }

    /** @param int[] $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    // ------------------------------------------------------------------------------------
    // Summary
    // ------------------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public function summary(): array
    {
        $calls = $this->calls();
        $c = static fn (callable $fn): int => count(array_filter($calls, $fn));

        $total = count($calls);
        $requested = $c(static fn ($s) => $s['requested']);
        $routed = $c(static fn ($s) => $s['routed']);
        $answered = $c(static fn ($s) => $s['answered']);
        $notRecorded = $c(static fn ($s) => $s['answer_status'] === 'outcome_not_recorded');
        $noResponse = $c(static fn ($s) => $s['answer_status'] === 'no_volunteer_response_logged');
        $fromConference = $c(static fn ($s) => ($s['answer_source'] ?? null) === 'conference_join');
        $routedNotAnswered = $c(static fn ($s) => $s['routed'] && !$s['answered'] && $s['answer_status'] !== 'outcome_not_recorded');
        $routedKnown = $routed - $notRecorded;
        $volunteerVoicemail = $c(static fn ($s) => $s['requested'] && !$s['answered'] && $s['voicemail']);
        $voicemailCalls = $c(static fn ($s) => $s['voicemail']);
        $abandoned = $c(static fn ($s) => $s['requested'] && !$s['answered'] && !$s['voicemail'] && !($s['forwarded'] ?? false) && $s['answer_status'] === 'not_connected');
        $forwarded = $c(static fn ($s) => $s['forwarded'] ?? false);
        $short = $c(static fn ($s) => $s['answer_status'] === 'connected_short');
        $unknown = $c(static fn ($s) => $s['answer_status'] === 'connected_unknown_duration');
        $menuOnly = $c(static fn ($s) => $s['category'] === 'menu_only');
        $minSeconds = ($this->dataset['answered_rule'] ?? 'accepted') === 'min_seconds';

        // Attempt-level figures come from the attempt events themselves (volunteer legs often carry
        // their own CallSid), attributed by the service body on each attempt event.
        $attemptEvents = $this->attempts();
        $countAttempts = static fn (string $name): int => count(array_filter($attemptEvents, static fn ($e) => $e['name'] === $name));
        $attempts = $countAttempts('VOLUNTEER_DIALED');
        $noanswer = $countAttempts('VOLUNTEER_NOANSWER');
        $rejected = $countAttempts('VOLUNTEER_REJECTED');
        $busy = $countAttempts('VOLUNTEER_NUMBER_BUSY');
        $badNumber = $countAttempts('VOLUNTEER_NUMBER_BAD');

        $durations = array_values(array_filter(array_map(static fn ($s) => $s['dur'], $calls), static fn ($d) => $d !== null));
        $conversations = array_values(array_filter(array_map(static fn ($s) => $s['conversation'], $calls), static fn ($d) => $d !== null));

        $meetingVoice = $this->interactions('MEETING_SEARCH');
        $jftVoice = $this->interactions('JFT_LOOKUP');
        $spadVoice = $this->interactions('SPAD_LOOKUP');

        return [
            'total_calls' => $this->metric('calls', 'call', static fn () => $total),
            'volunteer_requested_calls' => $this->metric('volunteer_requested', 'call', static fn () => $requested),
            'volunteer_routed_calls' => $this->metric('volunteer_routing', 'call', static fn () => $routed),
            'answered_calls' => $this->metric('answered', 'call', static fn () => $answered),
            'routed_not_answered_calls' => $this->metric('answered', 'call', static fn () => $routedNotAnswered),
            'connected_too_short_calls' => $minSeconds ? $this->metric('answered', 'call', static fn () => $short) : self::unavailable('call', 'Not used: answered calls follow YAP (volunteer accepted), not a minimum length.'),
            'connected_unknown_duration_calls' => $minSeconds ? $this->metric('answered', 'call', static fn () => $unknown) : self::unavailable('call', 'Not used: answered calls follow YAP (volunteer accepted), not a minimum length.'),
            'answer_rate' => $this->metric('answered', 'call', static fn () => self::pct($answered, $routedKnown), 'percent'),
            'missed_rate' => $this->metric('answered', 'call', static fn () => self::pct($routedNotAnswered, $routedKnown), 'percent'),
            'routed_outcome_not_recorded_calls' => $this->metric('volunteer_routing', 'call', static fn () => $notRecorded),
            'no_volunteer_response_logged_calls' => $this->metric('volunteer_routing', 'call', static fn () => $noResponse),
            'answered_from_conference_record' => $this->metric('answered', 'call', static fn () => $fromConference),
            'voicemail_calls' => $this->metric('voicemail', 'call', static fn () => $voicemailCalls),
            'volunteer_calls_to_voicemail' => $this->metric('voicemail', 'call', static fn () => $volunteerVoicemail),
            'voicemail_rate' => $this->metric('voicemail', 'call', static fn () => self::pct($volunteerVoicemail, $requested), 'percent'),
            'abandoned_volunteer_calls' => $this->metric('answered', 'call', static fn () => $abandoned),
            'forwarded_to_helpline_calls' => $this->metric('helpline_forward', 'call', static fn () => $forwarded),
            'ended_before_selection_calls' => $this->metric('menu_only', 'call', static fn () => $menuOnly),

            'volunteer_dial_attempts' => $this->metric('volunteer_routing', 'attempt', static fn () => $attempts),
            'attempts_not_answered' => $this->metric('no_answer_attempts', 'attempt', static fn () => $noanswer),
            'attempts_rejected' => $this->metric('rejected_attempts', 'attempt', static fn () => $rejected),
            'attempts_busy' => $this->metric('busy_attempts', 'attempt', static fn () => $busy),
            'attempts_bad_number' => $this->metric('bad_number_attempts', 'attempt', static fn () => $badNumber),
            'average_attempts_per_routed_call' => $this->metric('volunteer_routing', 'call', static fn () => $routed > 0 ? round($attempts / $routed, 2) : null),

            'total_call_seconds' => $this->metric('call_durations', 'call', static fn () => array_sum($durations), 'seconds'),
            'average_call_seconds' => $this->metric('call_durations', 'call', static fn () => $durations ? round(array_sum($durations) / count($durations), 1) : null, 'seconds'),
            'median_call_seconds' => $this->metric('call_durations', 'call', static fn () => self::median($durations), 'seconds'),
            'min_call_seconds' => $this->metric('call_durations', 'call', static fn () => $durations ? min($durations) : null, 'seconds'),
            'max_call_seconds' => $this->metric('call_durations', 'call', static fn () => $durations ? max($durations) : null, 'seconds'),
            'calls_with_known_duration' => $this->metric('call_durations', 'call', static fn () => count($durations)),
            'total_conversation_seconds' => $this->metric('conversation_time', 'call', static fn () => array_sum($conversations), 'seconds'),
            'average_conversation_seconds' => $this->metric('conversation_time', 'call', static fn () => $conversations ? round(array_sum($conversations) / count($conversations), 1) : null, 'seconds'),

            'meeting_lookup_calls' => $this->metric('meeting_lookup_voice', 'call', fn () => $this->distinctRoots($meetingVoice)),
            'meeting_lookup_actions' => $this->metric('meeting_lookup_voice', 'interaction', static fn () => count($meetingVoice)),
            'sms_meeting_lookups' => $this->metric('meeting_lookup_sms', 'message', fn () => count($this->sms('MEETING_SEARCH_SMS'))),
            'sms_volunteer_requests' => $this->metric('volunteer_sms', 'message', fn () => count($this->sms('VOLUNTEER_SEARCH_SMS'))),
            'jft_calls' => $this->metric('jft_voice', 'call', fn () => $this->distinctRoots($jftVoice)),
            'jft_voice_plays' => $this->metric('jft_voice', 'interaction', static fn () => count($jftVoice)),
            'jft_sms' => $this->metric('jft_sms', 'message', fn () => count($this->sms('JFT_LOOKUP_SMS'))),
            'spad_calls' => $this->metric('spad_voice', 'call', fn () => $this->distinctRoots($spadVoice)),
            'spad_voice_plays' => $this->metric('spad_voice', 'interaction', static fn () => count($spadVoice)),
            'spad_sms' => $this->metric('spad_sms', 'message', fn () => count($this->sms('SPAD_LOOKUP_SMS'))),
        ];
    }

    /** @param array<int, array<string, mixed>> $interactions */
    private function distinctRoots(array $interactions): int
    {
        return count(array_unique(array_map(static fn ($i) => $i['root'], $interactions)));
    }

    /** How much of the selected activity could not be tied to a service body. @return array<string, mixed> */
    public function attributionReport(): array
    {
        $all = $this->dataset['sessions'];
        $bySource = [];
        $unassigned = 0;
        foreach ($all as $s) {
            if ($s['sb'] === null) {
                $unassigned++;
            }
            if ($this->filter->allows($s['sb'])) {
                $src = $s['sb_source'] ?? 'unassigned';
                $bySource[$src] = ($bySource[$src] ?? 0) + 1;
            }
        }
        return [
            'policy_sources' => $bySource,
            'installation_unassigned_calls' => $this->filter->ids === null ? $unassigned : null,
            // Correction applied to conference_participants times (database clock vs YAP's event clock).
            'participant_clock_offset_seconds' => (int) ($this->dataset['diagnostics']['participant_clock_offset_seconds'] ?? 0),
            'note' => 'Calls with no service body on any event and no configured line mapping are counted only under include_unassigned.',
        ];
    }

    // ------------------------------------------------------------------------------------
    // Trends & distributions
    // ------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function trends(string $granularity): array
    {
        $tz = $this->range->timezone;
        $buckets = [];
        $cursor = $this->bucketStart($this->range->start, $granularity);
        $guard = 0;
        while ($cursor < $this->range->end && $guard++ < 1000) {
            $key = $this->bucketKey($cursor, $granularity);
            $buckets[$key] = [
                'bucket' => $key,
                'start' => $cursor->format('Y-m-d'),
                'total_calls' => 0, 'volunteer_routed_calls' => 0, 'answered_calls' => 0,
                'voicemail_calls' => 0, 'meeting_lookup_actions' => 0, 'sms_meeting_lookups' => 0,
                'jft' => 0, 'spad' => 0, 'not_recorded' => 0,
            ];
            $cursor = $this->nextBucket($cursor, $granularity);
        }
        $keyOf = fn (int $ts): string => $this->bucketKey((new DateTimeImmutable('@' . $ts))->setTimezone($tz), $granularity);
        $inc = static function (array &$b, string $k, string $field): void {
            if (isset($b[$k])) {
                $b[$k][$field]++;
            }
        };

        foreach ($this->calls() as $s) {
            $k = $keyOf($s['start']);
            $inc($buckets, $k, 'total_calls');
            if ($s['routed']) {
                $inc($buckets, $k, 'volunteer_routed_calls');
            }
            if ($s['answer_status'] === 'outcome_not_recorded') {
                $inc($buckets, $k, 'not_recorded');
            }
            if ($s['answered']) {
                $inc($buckets, $k, 'answered_calls');
            }
            if ($s['voicemail']) {
                $inc($buckets, $k, 'voicemail_calls');
            }
        }
        foreach ($this->interactions() as $i) {
            $field = match ($i['name']) {
                'MEETING_SEARCH' => 'meeting_lookup_actions',
                'JFT_LOOKUP' => 'jft',
                'SPAD_LOOKUP' => 'spad',
                default => null,
            };
            if ($field !== null) {
                $inc($buckets, $keyOf($i['t']), $field);
            }
        }
        foreach ($this->sms() as $e) {
            $field = match ($e['name']) {
                'MEETING_SEARCH_SMS' => 'sms_meeting_lookups',
                'JFT_LOOKUP_SMS' => 'jft',
                'SPAD_LOOKUP_SMS' => 'spad',
                default => null,
            };
            if ($field !== null) {
                $inc($buckets, $keyOf($e['t']), $field);
            }
        }

        $requirements = [
            'volunteer_routed_calls' => 'volunteer_routing', 'answered_calls' => 'answered',
            'voicemail_calls' => 'voicemail', 'meeting_lookup_actions' => 'meeting_lookup_voice',
            'sms_meeting_lookups' => 'meeting_lookup_sms', 'jft' => 'jft_voice', 'spad' => 'spad_voice',
        ];
        $unavailable = [];
        foreach ($requirements as $field => $req) {
            $reason = $this->caps->missing($req);
            if ($reason !== null) {
                $unavailable[$field] = $reason;
            }
        }
        $series = array_values($buckets);
        foreach ($series as &$row) {
            foreach (array_keys($unavailable) as $field) {
                $row[$field] = null;
            }
            // Same definition as the summary: calls whose outcome YAP did not record are left out.
            $row['answer_rate'] = isset($unavailable['answered_calls']) ? null : self::pct((int) $row['answered_calls'], (int) $row['volunteer_routed_calls'] - (int) $row['not_recorded']);
            unset($row['not_recorded']);
        }
        unset($row);

        return [
            'granularity' => $granularity,
            'series' => $series,
            'unavailable' => $unavailable,
            'notes' => [
                'jft' => 'Voice plays plus SMS requests attributed to the selected service bodies.',
                'spad' => 'Voice plays plus SMS requests attributed to the selected service bodies.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function distributions(): array
    {
        $tz = $this->range->timezone;
        $hours = array_fill(0, 24, ['calls' => 0, 'routed' => 0, 'answered' => 0, 'not_recorded' => 0]);
        $days = array_fill(0, 7, ['calls' => 0, 'routed' => 0, 'answered' => 0, 'not_recorded' => 0]);
        foreach ($this->calls() as $s) {
            $local = (new DateTimeImmutable('@' . $s['start']))->setTimezone($tz);
            $h = (int) $local->format('G');
            $d = (int) $local->format('w');
            $hours[$h]['calls']++;
            $days[$d]['calls']++;
            if ($s['routed']) {
                $hours[$h]['routed']++;
                $days[$d]['routed']++;
            }
            if ($s['answer_status'] === 'outcome_not_recorded') {
                $hours[$h]['not_recorded']++;
                $days[$d]['not_recorded']++;
            }
            if ($s['answered']) {
                $hours[$h]['answered']++;
                $days[$d]['answered']++;
            }
        }
        $answeredOk = $this->caps->available('answered');
        $byHour = [];
        foreach ($hours as $h => $v) {
            $byHour[] = ['hour' => $h, 'calls' => $v['calls'], 'volunteer_routed_calls' => $v['routed'],
                'answered_calls' => $answeredOk ? $v['answered'] : null,
                'answer_rate' => $answeredOk ? self::pct($v['answered'], $v['routed'] - $v['not_recorded']) : null];
        }
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $byDay = [];
        foreach ($days as $d => $v) {
            $byDay[] = ['weekday' => $d, 'name' => $names[$d], 'calls' => $v['calls'], 'volunteer_routed_calls' => $v['routed'],
                'answered_calls' => $answeredOk ? $v['answered'] : null,
                'answer_rate' => $answeredOk ? self::pct($v['answered'], $v['routed'] - $v['not_recorded']) : null];
        }
        $peak = null;
        $max = 0;
        foreach ($hours as $h => $v) {
            if ($v['calls'] > $max) {
                $max = $v['calls'];
                $peak = $h;
            }
        }
        $weekend = $days[0]['calls'] + $days[6]['calls'];
        $weekday = array_sum(array_map(static fn ($v) => $v['calls'], $days)) - $weekend;
        return [
            'by_hour' => $byHour,
            'by_weekday' => $byDay,
            'peak_hour' => $peak,
            'weekend_calls' => $weekend,
            'weekday_calls' => $weekday,
            'timezone' => $tz->getName(),
        ];
    }

    // ------------------------------------------------------------------------------------
    // Volunteers
    // ------------------------------------------------------------------------------------

    /**
     * @param callable(string): string $idFor pseudonym for a normalised number
     * @param (callable(string): ?string)|null $nameFor display name resolver (null = names not permitted)
     * @return array<string, mixed>
     */
    public function volunteerSummary(callable $idFor, ?callable $nameFor): array
    {
        $reason = $this->caps->missing('volunteer_attribution');
        if ($reason !== null) {
            return ['available' => false, 'reason' => $reason, 'volunteers' => []];
        }
        $stats = [];
        $blank = ['routed' => 0, 'picked_up' => 0, 'no_answer' => 0, 'rejected' => 0, 'declined_no_key' => 0, 'busy' => 0, 'bad_number' => 0,
            'answered' => 0, 'conversation' => 0, 'taken_by_other' => 0, 'on_unanswered' => 0, 'first_pickup_lost' => 0, 'not_recorded' => 0,
            'last_answered' => null, 'last_activity' => null];
        $unattributedAttempts = 0;
        foreach ($this->attempts() as $e) {
            if ($e['vol'] === null) {
                $unattributedAttempts++;
                continue;
            }
            $v = $e['vol'];
            $stats[$v] ??= $blank;
            match ($e['name']) {
                'VOLUNTEER_DIALED' => $stats[$v]['routed']++,
                'VOLUNTEER_ANSWERED' => $stats[$v]['picked_up']++,
                'VOLUNTEER_NOANSWER' => $stats[$v]['no_answer']++,
                'VOLUNTEER_REJECTED' => $stats[$v]['rejected']++,
                'VOLUNTEER_NUMBER_BUSY' => $stats[$v]['busy']++,
                'VOLUNTEER_NUMBER_BAD' => $stats[$v]['bad_number']++,
                default => null,
            };
            $stats[$v]['last_activity'] = max($stats[$v]['last_activity'] ?? 0, $e['t']);
        }

        // Pick-ups per call. YAP blasts a call to every volunteer on shift; several phones may pick up, and the
        // first to press the key takes the call. For each volunteer that picked up, the call is then one of:
        //   accepted by them | taken by another volunteer (normal in a blast) | not answered by anyone.
        // Pick-ups on legs YAP did not link to the call are matched by dialed number and time.
        $globalPickups = [];
        foreach ($this->attempts() as $e) {
            if ($e['name'] === 'VOLUNTEER_ANSWERED' && $e['vol'] !== null) {
                $globalPickups[] = $e;
            }
        }
        usort($globalPickups, static fn ($a, $b) => $a['t'] <=> $b['t']);
        $globalRejects = array_values(array_filter($this->attempts(), static fn ($e) => $e['name'] === 'VOLUNTEER_REJECTED' && $e['vol'] !== null));
        usort($globalRejects, static fn ($a, $b) => $a['t'] <=> $b['t']);
        foreach ($this->calls() as $s) {
            if (!$s['routed']) {
                continue;
            }
            $dialed = [];
            $firstDial = null;
            $last = $s['start'];
            foreach ($s['timeline'] as $e) {
                $last = max($last, $e['t']);
                if ($e['name'] === 'VOLUNTEER_DIALED' && $e['vol'] !== null) {
                    $dialed[$e['vol']] = true;
                    $firstDial ??= $e['t'];
                }
            }
            if ($s['answer_status'] === 'outcome_not_recorded' || $s['answer_status'] === 'no_volunteer_response_logged') {
                foreach (array_keys($dialed) as $vol) {
                    $stats[(string) $vol] ??= $blank;
                    $stats[(string) $vol]['not_recorded']++;
                }
                continue;
            }
            // Declines with no key pressed count only when nobody took the call. In production, phones still
            // ringing when another volunteer accepts are logged as VOLUNTEER_REJECTED with "digits": null.
            if (!$s['answered'] && $firstDial !== null) {
                $seenReject = [];
                foreach ($globalRejects as $r) {
                    if ($r['t'] < $firstDial) {
                        continue;
                    }
                    if ($r['t'] > $last + 60) {
                        break;
                    }
                    $rk = $r['vol'] . '|' . $r['t'];
                    if (isset($dialed[$r['vol']]) && ($r['keyed'] ?? null) === false && !isset($seenReject[$rk])) {
                        $seenReject[$rk] = true;
                        $stats[$r['vol']] ??= $blank;
                        $stats[$r['vol']]['declined_no_key']++;
                    }
                }
            }
            $pickedUp = [];
            foreach ($s['pickups'] ?? [] as $p) {
                $pickedUp[$p['vol']] = min($pickedUp[$p['vol']] ?? PHP_INT_MAX, $p['t']);
            }
            if ($firstDial !== null) {
                foreach ($globalPickups as $p) {
                    if ($p['t'] < $firstDial) {
                        continue;
                    }
                    if ($p['t'] > $last + 60) {
                        break;
                    }
                    if (isset($dialed[$p['vol']]) && !isset($pickedUp[$p['vol']])) {
                        $pickedUp[$p['vol']] = $p['t'];
                    }
                }
            }
            if ($pickedUp === []) {
                continue;
            }
            asort($pickedUp);
            $first = (string) array_key_first($pickedUp);
            foreach ($pickedUp as $vol => $t) {
                $vol = (string) $vol;
                $stats[$vol] ??= $blank;
                if ($s['answered'] && $s['answered_by'] === $vol) {
                    continue;
                }
                if ($s['answered']) {
                    $stats[$vol]['taken_by_other']++;
                } else {
                    $stats[$vol]['on_unanswered']++;
                    if ($vol === $first) {
                        $stats[$vol]['first_pickup_lost']++;
                    }
                }
            }
        }

        $unattributedAnswers = 0;
        $inferred = 0;
        foreach ($this->calls() as $s) {
            if (!$s['answered']) {
                continue;
            }
            if ($s['answered_by'] === null) {
                $unattributedAnswers++;
                continue;
            }
            if ($s['answered_by_source'] === 'inferred_last_dial') {
                $inferred++;
            }
            $v = $s['answered_by'];
            $stats[$v] ??= $blank;
            $stats[$v]['answered']++;
            $stats[$v]['conversation'] += (int) $s['conversation'];
            $stats[$v]['last_answered'] = max($stats[$v]['last_answered'] ?? 0, $s['start']);
            $stats[$v]['last_activity'] = max($stats[$v]['last_activity'] ?? 0, $s['start']);
        }

        $tz = $this->range->timezone;
        $iso = static fn (?int $ts): ?string => $ts ? (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format(DATE_ATOM) : null;
        $rows = [];
        foreach ($stats as $number => $st) {
            $row = [
                'volunteer_id' => $idFor((string) $number),
                'routed_attempts' => $st['routed'],
                'answered_calls' => $st['answered'],
                'not_answered_attempts' => $this->caps->available('no_answer_attempts') ? $st['no_answer'] : null,
                'rejected_attempts' => $this->caps->available('rejected_attempts') ? $st['rejected'] : null,
                'busy_attempts' => $this->caps->available('busy_attempts') ? $st['busy'] : null,
                'bad_number_attempts' => $this->caps->available('bad_number_attempts') ? $st['bad_number'] : null,
                'picked_up' => $st['picked_up'],
                'pickups_taken_by_other' => $st['taken_by_other'],
                'pickups_on_unanswered_calls' => $st['on_unanswered'],
                'declined_without_key' => $st['declined_no_key'],
                'calls_lost_after_first_pickup' => $st['first_pickup_lost'],
                // A phone that keeps picking up but (almost) never accepts, and has either declined silently or
                // picked up on calls nobody answered: typical of voicemail or call forwarding.
                'possible_voicemail_pickup' => $st['picked_up'] >= 3
                    && $st['answered'] <= 0.1 * $st['picked_up']
                    && ($st['declined_no_key'] > 0 || $st['on_unanswered'] > 0),
                'attempts_outcome_not_recorded' => $st['not_recorded'],
                // Calls where YAP did not log what happened are left out of the volunteer's answer rate.
                'answer_rate' => self::pct($st['answered'], max(0, $st['routed'] - $st['not_recorded'])),
                'total_conversation_seconds' => $st['conversation'],
                'average_conversation_seconds' => $st['answered'] > 0 ? round($st['conversation'] / $st['answered'], 1) : null,
                'last_answered_at' => $iso($st['last_answered']),
                'last_activity_at' => $iso($st['last_activity']),
            ];
            if ($nameFor !== null) {
                $row['name'] = $nameFor((string) $number);
                // Last four digits only, so an administrator can match the volunteer in YAP when no name is found.
                $row['number_hint'] = '…' . substr((string) $number, -4);
            }
            $rows[] = $row;
        }
        usort($rows, static fn ($a, $b) => [$b['answered_calls'], $b['routed_attempts']] <=> [$a['answered_calls'], $a['routed_attempts']]);

        return [
            'available' => true,
            'volunteers' => $rows,
            'unattributed_attempts' => $unattributedAttempts,
            'unattributed_answered_calls' => $unattributedAnswers,
            'answers_attributed_by_inference' => $inferred,
            'limitations' => [
                'Routed attempts count every time YAP dialed the volunteer, including off-hours call blasting.',
                'A volunteer who was not on shift is never dialed, so low totals do not indicate poor performance.',
                'answer_rate is answered calls divided by routed attempts for that volunteer, leaving out calls where YAP did not log what happened after dialing (attempts_outcome_not_recorded).',
                'When YAP did not record which volunteer joined, the most recently dialed volunteer is used and counted in answers_attributed_by_inference.',
                'picked_up counts every time the volunteer\'s phone was answered; YAP then asks them to press a key to accept.',
                'Calls ring every volunteer on shift at once, so several phones can pick up; the first to accept takes the call. pickups_taken_by_other is normal.',
                'answered_calls credits only the volunteer YAP recorded as accepting (VOLUNTEER_IN_CONFERENCE), never just a pick-up.',
                'declined_without_key: the phone was answered but no key was pressed — usually voicemail or call forwarding.',
                'pickups_on_unanswered_calls / calls_lost_after_first_pickup: this phone picked up (first) but nobody took the call.',
                'possible_voicemail_pickup: 3+ pick-ups, accepted at most 1 in 10, and at least one silent decline or lost call.',
            ],
        ];
    }

    // ------------------------------------------------------------------------------------
    // Topic reports
    // ------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function meetingLookups(): array
    {
        $voice = $this->interactions('MEETING_SEARCH');
        $sms = $this->sms('MEETING_SEARCH_SMS');
        $byArea = [];
        foreach ($voice as $i) {
            $k = (string) ($i['sb'] ?? 'unassigned');
            $byArea[$k]['voice_actions'] = ($byArea[$k]['voice_actions'] ?? 0) + 1;
        }
        foreach ($sms as $e) {
            $k = (string) ($e['sb'] ?? 'unassigned');
            $byArea[$k]['sms_lookups'] = ($byArea[$k]['sms_lookups'] ?? 0) + 1;
        }
        $areas = [];
        foreach ($byArea as $k => $v) {
            $areas[] = ['service_body_id' => $k === 'unassigned' ? null : (int) $k,
                'voice_actions' => $v['voice_actions'] ?? 0, 'sms_lookups' => $v['sms_lookups'] ?? 0];
        }
        return [
            'meeting_lookup_calls' => $this->metric('meeting_lookup_voice', 'call', fn () => $this->distinctRoots($voice)),
            'meeting_lookup_actions' => $this->metric('meeting_lookup_voice', 'interaction', static fn () => count($voice)),
            'sms_meeting_lookups' => $this->metric('meeting_lookup_sms', 'message', static fn () => count($sms)),
            'successful_vs_failed' => self::unavailable('interaction', 'YAP does not record whether a meeting search returned results.'),
            'by_service_body' => $areas,
            'attribution_note' => 'Meeting searches are attributed by the service body on the search event, or by line mapping when configured. Searches YAP recorded without a service body appear as unassigned.',
        ];
    }

    /**
     * @param array<string, mixed>|null $installation full-installation engine for labelled totals
     * @return array<string, mixed>
     */
    public function meditations(?MetricEngine $installation): array
    {
        $out = [
            'area_attributed' => [
                'jft_voice' => $this->metric('jft_voice', 'interaction', fn () => count($this->interactions('JFT_LOOKUP'))),
                'jft_sms' => $this->metric('jft_sms', 'message', fn () => count($this->sms('JFT_LOOKUP_SMS'))),
                'spad_voice' => $this->metric('spad_voice', 'interaction', fn () => count($this->interactions('SPAD_LOOKUP'))),
                'spad_sms' => $this->metric('spad_sms', 'message', fn () => count($this->sms('SPAD_LOOKUP_SMS'))),
            ],
            'attribution_note' => 'Area figures include only meditation requests that YAP tied to a service body (or that arrived on a mapped helpline number).',
        ];
        $out['area_attributed']['combined'] = self::combine($out['area_attributed']);
        if ($installation !== null) {
            $inst = [
                'jft_voice' => $installation->metric('jft_voice', 'interaction', fn () => count($installation->interactions('JFT_LOOKUP'))),
                'jft_sms' => $installation->metric('jft_sms', 'message', fn () => count($installation->sms('JFT_LOOKUP_SMS'))),
                'spad_voice' => $installation->metric('spad_voice', 'interaction', fn () => count($installation->interactions('SPAD_LOOKUP'))),
                'spad_sms' => $installation->metric('spad_sms', 'message', fn () => count($installation->sms('SPAD_LOOKUP_SMS'))),
            ];
            $inst['combined'] = self::combine($inst);
            $out['installation_wide'] = $inst;
            $out['installation_note'] = 'Installation-wide totals cover every line on this YAP server and are not specific to any Area.';
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function smsSummary(): array
    {
        $all = $this->sms();
        $byName = [];
        foreach ($all as $e) {
            $k = $e['name'] ?? 'unknown';
            $byName[$k] = ($byName[$k] ?? 0) + 1;
        }
        $daily = [];
        $tz = $this->range->timezone;
        foreach ($all as $e) {
            $d = (new DateTimeImmutable('@' . $e['t']))->setTimezone($tz)->format('Y-m-d');
            $daily[$d] = ($daily[$d] ?? 0) + 1;
        }
        ksort($daily);
        $series = [];
        foreach ($daily as $d => $n) {
            $series[] = ['date' => $d, 'sms_events' => $n];
        }
        return [
            'total_sms_events' => self::value(count($all), 'message'),
            'sms_conversations' => self::value(count(array_unique(array_map(static fn ($e) => $e['cs'], $all))), 'message'),
            'sms_meeting_lookups' => $this->metric('meeting_lookup_sms', 'message', static fn () => $byName['MEETING_SEARCH_SMS'] ?? 0),
            'sms_volunteer_requests' => $this->metric('volunteer_sms', 'message', static fn () => $byName['VOLUNTEER_SEARCH_SMS'] ?? 0),
            'sms_jft_requests' => $this->metric('jft_sms', 'message', static fn () => $byName['JFT_LOOKUP_SMS'] ?? 0),
            'sms_successful_vs_failed' => self::unavailable('message', 'YAP does not record whether an SMS meeting search returned results.'),
            'by_event_type' => $byName,
            'daily' => $series,
        ];
    }

    /** @return array<string, mixed> Compact Area Service dashboard figures. */
    public function areaDashboard(?MetricEngine $installation): array
    {
        $sum = $this->summary();
        $inst = $installation?->summary();
        $scoped = static fn (array $m, string $scope): array => $m + ['scope' => $scope];

        $out = [
            'volunteer_calls' => $sum['volunteer_routed_calls'],
            'calls_answered' => $sum['answered_calls'],
            'answer_rate' => $sum['answer_rate'],
            'calls_forwarded' => $sum['forwarded_to_helpline_calls'],
            'meeting_lookups' => $sum['meeting_lookup_calls'],
            'sms_meeting_lookups' => $sum['sms_meeting_lookups'],
            'sms_volunteer_lookups' => $sum['sms_volunteer_requests'],
            'jft' => $scoped($sum['jft_calls'], 'area'),
            'sms_jft' => $scoped($sum['jft_sms'], 'area'),
            'spad' => $scoped($sum['spad_calls'], 'area'),
            'sms_spad' => $scoped($sum['spad_sms'], 'area'),
        ];
        // Meditation requests are often recorded without a service body; installation-wide totals are
        // returned separately and labelled so a dashboard never presents them as Area figures.
        // Verified in production: YAP stores meeting searches, JFT/SPAD and every text message without a
        // service body, so Area figures for these are usually 0 and the server-wide totals carry the activity.
        $map = [
            'jft' => 'jft_calls', 'sms_jft' => 'jft_sms', 'spad' => 'spad_calls', 'sms_spad' => 'spad_sms',
            'meeting_lookups' => 'meeting_lookup_calls', 'sms_meeting_lookups' => 'sms_meeting_lookups',
            'sms_volunteer_lookups' => 'sms_volunteer_requests',
        ];
        foreach ($map as $key => $metric) {
            $out[$key . '_installation_wide'] = $inst !== null ? $scoped($inst[$metric], 'installation') : null;
        }
        $out['labels'] = [
            'volunteer_calls' => 'Unique calls YAP dialed at least one volunteer for',
            'calls_answered' => 'Calls a volunteer answered and accepted (as in YAP)',
            'calls_forwarded' => 'Calls YAP forwarded to an outside helpline number (answering is not visible to YAP)',
            'meeting_lookups' => 'Unique phone calls that searched for meetings',
            'sms_meeting_lookups' => 'Text messages asking for meetings',
            'sms_volunteer_lookups' => 'Text messages asking for a volunteer',
            'jft' => 'Phone calls that played Just for Today',
            'sms_jft' => 'Text messages asking for Just for Today',
            'spad' => 'Phone calls that played Spiritual Principle a Day',
            'sms_spad' => 'Text messages asking for Spiritual Principle a Day',
            '*_installation_wide' => 'The same figure for every line on this YAP server (not Area-specific)',
        ];
        return $out;
    }

    /**
     * Sum metric values; available if at least one part is available. Unavailable parts are listed.
     *
     * @param array<int|string, array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    public static function combine(array $parts): array
    {
        $value = 0;
        $any = false;
        $missing = [];
        foreach ($parts as $key => $p) {
            if (!is_array($p) || !array_key_exists('available', $p)) {
                continue;
            }
            if ($p['available']) {
                $any = true;
                $value += (int) $p['value'];
            } else {
                $missing[] = is_string($key) ? $key : ($p['reason'] ?? 'unavailable');
            }
        }
        if (!$any) {
            return self::unavailable('interaction', 'None of the component metrics are available.');
        }
        $m = self::value($value, 'interaction');
        if ($missing !== []) {
            $m['partial'] = true;
            $m['excluded_components'] = $missing;
        }
        return $m;
    }

    // ------------------------------------------------------------------------------------
    // Bucketing helpers
    // ------------------------------------------------------------------------------------

    private function bucketStart(DateTimeImmutable $d, string $g): DateTimeImmutable
    {
        $d = $d->setTime(0, 0);
        return match ($g) {
            'week' => DateRange::weekStart($d, $this->weekStartsOn),
            'month' => $d->modify('first day of this month'),
            'quarter' => DateRange::quarterStart($d),
            'year' => $d->setDate((int) $d->format('Y'), 1, 1),
            default => $d,
        };
    }

    private function nextBucket(DateTimeImmutable $d, string $g): DateTimeImmutable
    {
        return match ($g) {
            'week' => $d->modify('+7 days'),
            'month' => $d->modify('first day of next month'),
            'quarter' => $d->modify('first day of this month')->modify('+3 months'),
            'year' => $d->modify('+1 year'),
            default => $d->modify('+1 day'),
        };
    }

    private function bucketKey(DateTimeImmutable $d, string $g): string
    {
        $start = $this->bucketStart($d, $g);
        return match ($g) {
            'week' => 'W' . $start->format('Y-m-d'),
            'month' => $start->format('Y-m'),
            'quarter' => $start->format('Y') . '-Q' . (intdiv((int) $start->format('n') - 1, 3) + 1),
            'year' => $start->format('Y'),
            default => $start->format('Y-m-d'),
        };
    }
}
