<?php

declare(strict_types=1);

namespace YapReporting\Reporting;

use YapReporting\Yap\EventCatalog;

/**
 * Turns raw YAP rows into one "session" per unique incoming call.
 *
 * YAP writes a Twilio call leg for the caller and one for every volunteer it dials. Legs that
 * shared a conference are linked through conference_participants (conferencesid). This class
 * joins those legs (union-find), so a call that rang five volunteers is ONE incoming call with
 * five routing attempts — never five calls.
 *
 * ANSWERED (call level): a volunteer leg joined the caller's conference and stayed connected for
 * MORE than `answeredMinSeconds`. Ringing, rejecting, voicemail, menu interaction, or a volunteer
 * who picked up but whose caller had already gone do not count.
 *
 * Input rows are normalised arrays produced by DatasetLoader. Phone numbers never enter this class
 * except the dialed volunteer number (already normalised to digits) used as an attribution key.
 */
final class SessionBuilder
{
    public const VOLUNTEER_LEG_EVENTS = [
        'VOLUNTEER_ANSWERED', 'VOLUNTEER_REJECTED', 'VOLUNTEER_NOANSWER',
        'VOLUNTEER_ANSWERED_BUT_CALLER_HUP', 'VOLUNTEER_HUP', 'VOLUNTEER_IN_CONFERENCE',
        'VOLUNTEER_NUMBER_BAD', 'VOLUNTEER_NUMBER_BUSY',
    ];
    public const ROUTING_EVENTS = ['HELPLINE_ROUTE', 'VOLUNTEER_DIALED', 'VOLUNTEER_SEARCH', 'VOLUNTEER_SEARCH_FORCE_DIALED', 'VOICEMAIL'];
    public const ATTEMPT_EVENTS = ['VOLUNTEER_DIALED', 'VOLUNTEER_ANSWERED', 'VOLUNTEER_NOANSWER', 'VOLUNTEER_REJECTED', 'VOLUNTEER_NUMBER_BUSY', 'VOLUNTEER_NUMBER_BAD'];
    public const INTERACTION_EVENTS = ['MEETING_SEARCH', 'JFT_LOOKUP', 'SPAD_LOOKUP'];
    /** Events that show YAP logged what happened after dialing a volunteer. */
    public const OUTCOME_EVENTS = [
        'VOLUNTEER_ANSWERED', 'VOLUNTEER_REJECTED', 'VOLUNTEER_NOANSWER', 'VOLUNTEER_ANSWERED_BUT_CALLER_HUP',
        'VOLUNTEER_NUMBER_BUSY', 'VOLUNTEER_NUMBER_BAD', 'VOLUNTEER_IN_CONFERENCE',
    ];

    /** @var array<string, string> union-find parent pointers */
    private array $parent = [];

    /** @var array<int, array<string, mixed>> volunteer outcome events (any leg), for time matching */
    private array $outcomeEvents = [];

    /** @var array<string, bool> legs YAP itself recorded as a parent (caller) call */
    private array $callerHints = [];

    /** @var array<string, int> service body YAP cached for a parent call */
    private array $linkSb = [];

    /** @param array<string, int> $lineMapping normalised helpline digits => service body id */
    public function __construct(
        private readonly int $answeredMinSeconds,
        private readonly int $callerRole,
        private readonly int $volunteerRole,
        private readonly string $attributionPolicy,
        private readonly array $lineMapping,
        // 'accepted' (default, matches YAP): answered when a volunteer accepted and was connected to the caller.
        // 'min_seconds': additionally require the connection to last longer than $answeredMinSeconds.
        private readonly string $answeredRule = 'accepted',
    ) {
    }

    /**
     * @param array<int, array{cs: string, t: int, eid: int, name: ?string, sb: ?int, vol: ?string}> $events
     * @param array<int, array{cs: string, conf: string, role: ?int, t: ?int}> $participants
     * @param array<int, array{cs: string, start: ?int, end: ?int, dur: ?int, line: ?string, dir: ?string}> $records
     * @param array<int, array{parent: string, child: string, sb: ?int}> $links rows from YAP's cache_records_conference_participants
     * @return array{sessions: array<int, array<string, mixed>>, sms: array<int, array<string, mixed>>, attempts: array<int, array<string, mixed>>, diagnostics: array<string, int>}
     */
    public function build(array $events, array $participants, array $records, int $fromTs, int $toTs, array $links = []): array
    {
        $this->parent = [];
        $this->outcomeEvents = [];
        $sms = [];
        $attempts = [];
        $eventsByLeg = [];
        $unknownIds = 0;

        foreach ($events as $e) {
            if ($e['name'] === null) {
                $unknownIds++;
            }
            if (EventCatalog::isSmsName($e['name']) || !empty($e['sms'])) {
                if ($e['t'] >= $fromTs && $e['t'] < $toTs) {
                    $sms[] = $e;
                }
                continue;
            }
            $this->add($e['cs']);
            $eventsByLeg[$e['cs']][] = $e;
            if ($e['name'] !== null && in_array($e['name'], self::OUTCOME_EVENTS, true) && ($e['vol'] ?? null) !== null) {
                $this->outcomeEvents[] = $e;
            }
            if ($e['name'] !== null && in_array($e['name'], self::ATTEMPT_EVENTS, true) && $e['t'] >= $fromTs && $e['t'] < $toTs) {
                $attempts[] = $e;
            }
        }

        $roles = [];
        $joinTimes = [];
        foreach ($participants as $p) {
            $this->add($p['cs']);
            $confKey = 'conf:' . $p['conf'];
            $this->add($confKey);
            $this->union($p['cs'], $confKey);
            if ($p['role'] !== null) {
                $roles[$p['cs']] = $p['role'];
            }
            if ($p['t'] !== null && (!isset($joinTimes[$p['cs']]) || $p['t'] < $joinTimes[$p['cs']])) {
                $joinTimes[$p['cs']] = $p['t'];
            }
        }

        // YAP's own parent→child leg map: the parent is the caller's leg.
        $this->callerHints = [];
        $this->linkSb = [];
        foreach ($links as $l) {
            if ($l['parent'] === '' || $l['child'] === '') {
                continue;
            }
            $this->add($l['parent']);
            $this->add($l['child']);
            $this->union($l['parent'], $l['child']);
            $this->callerHints[$l['parent']] = true;
            if ($l['sb'] !== null && $l['sb'] > 0) {
                $this->linkSb[$l['parent']] = $l['sb'];
            }
        }

        $recordsByLeg = [];
        foreach ($records as $r) {
            $this->add($r['cs']);
            $recordsByLeg[$r['cs']] = $r;
        }

        usort($this->outcomeEvents, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);

        $components = [];
        foreach (array_keys($this->parent) as $key) {
            $key = (string) $key;
            if (str_starts_with($key, 'conf:')) {
                continue;
            }
            $components[$this->find($key)][] = $key;
        }

        $sessions = [];
        $orphans = 0;
        foreach ($components as $legs) {
            $session = $this->buildSession($legs, $eventsByLeg, $roles, $joinTimes, $recordsByLeg);
            if ($session === null) {
                $orphans++;
                continue;
            }
            if ($session['start'] < $fromTs || $session['start'] >= $toTs) {
                continue;
            }
            $sessions[] = $session;
        }
        usort($sessions, static fn (array $a, array $b): int => [$a['start'], $a['root']] <=> [$b['start'], $b['root']]);

        return [
            'sessions' => $sessions,
            'sms' => $sms,
            'attempts' => $attempts,
            'diagnostics' => [
                'orphan_volunteer_legs' => $orphans,
                'events_with_unknown_event_id' => $unknownIds,
            ],
        ];
    }

    /**
     * @param string[] $legs
     * @param array<string, array<int, array<string, mixed>>> $eventsByLeg
     * @param array<string, int> $roles
     * @param array<string, int> $joinTimes
     * @param array<string, array<string, mixed>> $recordsByLeg
     * @return array<string, mixed>|null
     */
    private function buildSession(array $legs, array $eventsByLeg, array $roles, array $joinTimes, array $recordsByLeg): ?array
    {
        $callerLegs = [];
        $volunteerLegs = [];
        foreach ($legs as $cs) {
            $role = $roles[$cs] ?? null;
            if (isset($this->callerHints[$cs]) && $role !== $this->volunteerRole) {
                $callerLegs[] = $cs;
                continue;
            }
            if ($role === $this->volunteerRole) {
                $volunteerLegs[] = $cs;
                continue;
            }
            if ($role === $this->callerRole) {
                $callerLegs[] = $cs;
                continue;
            }
            $dir = $recordsByLeg[$cs]['dir'] ?? null;
            if (is_string($dir) && str_starts_with(strtolower($dir), 'outbound')) {
                $volunteerLegs[] = $cs;
                continue;
            }
            $hasCallerEvent = false;
            foreach ($eventsByLeg[$cs] ?? [] as $e) {
                if ($e['name'] === null || !in_array($e['name'], self::VOLUNTEER_LEG_EVENTS, true)) {
                    $hasCallerEvent = true;
                    break;
                }
            }
            if ($hasCallerEvent || isset($recordsByLeg[$cs])) {
                $callerLegs[] = $cs;
            } else {
                $volunteerLegs[] = $cs;
            }
        }
        if ($callerLegs === []) {
            return null; // Only volunteer-leg activity: not an incoming call.
        }

        $legStart = function (string $cs) use ($recordsByLeg, $eventsByLeg): int {
            $candidates = [];
            if (isset($recordsByLeg[$cs]['start'])) {
                $candidates[] = (int) $recordsByLeg[$cs]['start'];
            }
            foreach ($eventsByLeg[$cs] ?? [] as $e) {
                $candidates[] = $e['t'];
            }
            return $candidates === [] ? PHP_INT_MAX : min($candidates);
        };
        usort($callerLegs, static fn (string $a, string $b): int => [$legStart($a), $a] <=> [$legStart($b), $b]);
        $root = $callerLegs[0];
        $rootRecord = $recordsByLeg[$root] ?? null;

        $all = [];
        foreach ($legs as $cs) {
            foreach ($eventsByLeg[$cs] ?? [] as $e) {
                $all[] = $e;
            }
        }
        usort($all, static fn (array $a, array $b): int => [$a['t'], $a['eid']] <=> [$b['t'], $b['eid']]);

        $startCandidates = [];
        if ($rootRecord !== null && $rootRecord['start'] !== null) {
            $startCandidates[] = (int) $rootRecord['start'];
        }
        if ($all !== []) {
            $startCandidates[] = $all[0]['t'];
        }
        if ($startCandidates === []) {
            return null;
        }
        $start = min($startCandidates);

        $count = [];
        foreach ($all as $e) {
            if ($e['name'] !== null) {
                $count[$e['name']] = ($count[$e['name']] ?? 0) + 1;
            }
        }
        $n = static fn (string $name): int => $count[$name] ?? 0;

        $attempts = $n('VOLUNTEER_DIALED');
        $requested = ($n('VOLUNTEER_SEARCH') + $n('VOLUNTEER_SEARCH_FORCE_DIALED') + $n('HELPLINE_ROUTE') + $attempts) > 0;
        $routed = $attempts > 0;
        $voicemail = $n('VOICEMAIL') > 0;
        // HELPLINE_ROUTE without any volunteer dial = YAP forwarded the call to an outside helpline
        // number (call handling "helpline" mode). YAP cannot see whether that number answered.
        $forwarded = $n('HELPLINE_ROUTE') > 0 && $attempts === 0;

        // ---- Answer analysis -------------------------------------------------------------
        // Verified against production YAP: a volunteer's leg is added to conference_participants (role 2) as
        // soon as the phone is PICKED UP, before the volunteer presses a key, so those rows cannot tell who took
        // the call. The accept is recorded as VOLUNTEER_IN_CONFERENCE (12) on the volunteer's own leg, carrying
        // that volunteer's number. Only those events count as answering.
        $joins = [];
        foreach ($all as $e) {
            if ($e['name'] === 'VOLUNTEER_IN_CONFERENCE') {
                $joins[] = ['leg' => $e['cs'], 't' => $e['t'], 'vol' => $e['vol'] ?? $this->legVolunteer($eventsByLeg[$e['cs']] ?? []),
                    'own_leg' => !in_array($e['cs'], $callerLegs, true)];
            }
        }

        // Did YAP log what happened after dialing? Production data shows calls where it logged only the dial
        // (VOLUNTEER_DIALED) and the caller's hang-up — no pick-up, accept or decline at all. Outcome events on
        // volunteer legs that YAP did not link to this call are matched by dialed number and time.
        $dialedVols = [];
        $firstDial = null;
        $lastT = $all !== [] ? $all[count($all) - 1]['t'] : 0;
        foreach ($all as $e) {
            if ($e['name'] === 'VOLUNTEER_DIALED') {
                $firstDial ??= $e['t'];
                if ($e['vol'] !== null) {
                    $dialedVols[$e['vol']] = true;
                }
            }
        }
        $outcomeLogged = false;
        foreach ($all as $e) {
            if ($e['name'] !== null && in_array($e['name'], self::OUTCOME_EVENTS, true)) {
                $outcomeLogged = true;
                break;
            }
        }
        if (!$outcomeLogged && $firstDial !== null) {
            foreach ($this->outcomeEvents as $e) {
                if ($e['t'] < $firstDial) {
                    continue;
                }
                if ($e['t'] > $lastT + 60) {
                    break;
                }
                if (isset($dialedVols[$e['vol']])) {
                    $outcomeLogged = true;
                    break;
                }
            }
        }
        $joinSource = 'accept_event';
        if ($joins === [] && $attempts > 0 && !$outcomeLogged) {
            // No outcome logged: fall back to YAP's conference record. A volunteer leg that joined the caller's
            // conference is counted as answered (and reported separately as not confirmed by an accept event).
            foreach ($volunteerLegs as $v) {
                if (($roles[$v] ?? null) === $this->volunteerRole && isset($joinTimes[$v])) {
                    $joins[] = ['leg' => $v, 't' => $joinTimes[$v], 'vol' => count($dialedVols) === 1 ? (string) array_key_first($dialedVols) : null, 'own_leg' => true];
                }
            }
            $joinSource = 'conference_join';
        }

        $best = null;
        $accepted = null;
        $knownShort = 0;
        $unknown = 0;
        foreach ($joins as $j) {
            $vol = $j['vol'];
            $volSource = $vol !== null ? 'event' : null;
            if ($vol === null && $j['t'] !== null && $joinSource === 'accept_event') {
                $vol = $this->inferVolunteer($all, $j['t']);
                $volSource = $vol !== null ? 'inferred_last_dial' : null;
            }
            if ($vol === null && $joinSource === 'conference_join') {
                $volSource = null; // several numbers dialed and none logged: do not guess who answered
            }
            $conversation = $j['t'] === null ? null : $this->conversationSeconds($j, $all, $callerLegs, $recordsByLeg, $rootRecord);
            if ($accepted === null || ($conversation ?? -1) > ($accepted['conversation'] ?? -1)) {
                $accepted = ['conversation' => $conversation, 'vol' => $vol, 'vol_source' => $volSource];
            }
            if ($conversation === null) {
                $unknown++;
                continue;
            }
            if ($conversation > $this->answeredMinSeconds) {
                if ($best === null || $conversation > $best['conversation']) {
                    $best = ['conversation' => $conversation, 'vol' => $vol, 'vol_source' => $volSource];
                }
            } else {
                $knownShort++;
            }
        }

        if ($this->answeredRule !== 'min_seconds') {
            // YAP's rule: the volunteer accepted the call and was connected to the caller
            // (VOLUNTEER_IN_CONFERENCE / volunteer joined the conference). Length does not matter.
            $best = $accepted;
        }
        if ($best !== null && $joinSource === 'conference_join') {
            $best['vol_source'] = $best['vol'] !== null ? 'only_number_dialed' : null;
        }
        if ($best !== null) {
            $answerStatus = 'answered';
        } elseif ($unknown > 0) {
            $answerStatus = 'connected_unknown_duration';
        } elseif ($knownShort > 0) {
            $answerStatus = 'connected_short';
        } elseif ($attempts > 0 && !$outcomeLogged && !$voicemail) {
            // Dialed, then nothing about any volunteer's phone. If YAP recorded the caller in the conference, the
            // conference record is complete and no volunteer ever joined: the caller was not connected. (Verified
            // in production: the caller's role-1 row exists, no role-2 row.) That is not answered at call level,
            // but it is not held against the dialed volunteers, because YAP logged no response from their phones.
            $callerInConference = false;
            foreach ($callerLegs as $cl) {
                if (($roles[$cl] ?? null) === $this->callerRole) {
                    $callerInConference = true;
                    break;
                }
            }
            $answerStatus = $callerInConference ? 'no_volunteer_response_logged' : 'outcome_not_recorded';
        } else {
            $answerStatus = 'not_connected';
        }
        $answered = $answerStatus === 'answered';

        // ---- Area attribution ---------------------------------------------------------------
        $lineSb = null;
        if ($rootRecord !== null && $rootRecord['line'] !== null && isset($this->lineMapping[$rootRecord['line']])) {
            $lineSb = $this->lineMapping[$rootRecord['line']];
        }
        [$sb, $sbSource] = $this->attribute($all, $lineSb);
        if ($sb === null && isset($this->linkSb[$root])) {
            $sb = $this->linkSb[$root];
            $sbSource = 'yap_call_cache';
        }

        // ---- Category & outcome -------------------------------------------------------------
        $meeting = $n('MEETING_SEARCH');
        $jft = $n('JFT_LOOKUP');
        $spad = $n('SPAD_LOOKUP');
        $dialback = $n('DIALBACK') > 0;

        if ($requested) {
            $category = 'volunteer';
            $outcome = match (true) {
                $answered => 'answered',
                $voicemail => 'voicemail',
                $answerStatus === 'connected_short' => 'connected_short',
                $answerStatus === 'connected_unknown_duration' => 'connected_unknown_duration',
                $answerStatus === 'outcome_not_recorded' => 'outcome_not_recorded',
                $answerStatus === 'no_volunteer_response_logged' => 'no_volunteer_response_logged',
                $routed => 'not_answered',
                $forwarded => 'forwarded',
                default => 'not_routed',
            };
        } elseif ($voicemail) {
            $category = 'voicemail';
            $outcome = 'voicemail';
        } elseif ($meeting > 0) {
            $category = 'meeting_lookup';
            $outcome = 'automated';
        } elseif ($jft + $spad > 0) {
            $category = 'meditation';
            $outcome = 'automated';
        } elseif ($dialback) {
            $category = 'dialback';
            $outcome = 'dialback';
        } else {
            $category = 'menu_only';
            $outcome = 'ended_before_selection';
        }

        $duration = null;
        if ($rootRecord !== null) {
            if ($rootRecord['dur'] !== null) {
                $duration = max(0, (int) $rootRecord['dur']);
            } elseif ($rootRecord['start'] !== null && $rootRecord['end'] !== null) {
                $duration = max(0, (int) $rootRecord['end'] - (int) $rootRecord['start']);
            }
        }

        $interactions = [];
        $timeline = [];
        foreach ($all as $e) {
            $isCallerLeg = in_array($e['cs'], $callerLegs, true);
            $timeline[] = ['t' => $e['t'], 'name' => $e['name'], 'eid' => $e['eid'], 'sb' => $e['sb'], 'leg' => $isCallerLeg ? 'caller' : 'volunteer', 'vol' => $e['vol'], 'keyed' => $e['keyed'] ?? null];
            if ($e['name'] !== null && in_array($e['name'], self::INTERACTION_EVENTS, true)) {
                $eventSb = $e['sb'] !== null && $e['sb'] > 0 ? $e['sb'] : null;
                $interactions[] = [
                    'name' => $e['name'],
                    't' => $e['t'],
                    'sb' => $eventSb ?? $lineSb,
                    'sb_source' => $eventSb !== null ? 'event' : ($lineSb !== null ? 'line_mapping' : null),
                ];
            }
        }

        return [
            'root' => $root,
            'start' => $start,
            'dur' => $duration,
            'sb' => $sb,
            'sb_source' => $sbSource,
            'legs' => count($legs),
            'requested' => $requested,
            'routed' => $routed,
            'attempts' => $attempts,
            'noanswer' => $n('VOLUNTEER_NOANSWER'),
            'rejected' => $n('VOLUNTEER_REJECTED'),
            'voicemail' => $voicemail,
            'forwarded' => $forwarded,
            'meeting' => $meeting,
            'jft' => $jft,
            'spad' => $spad,
            'dialback' => $dialback,
            'pickups' => array_values(array_filter(array_map(
                static fn (array $e): ?array => $e['name'] === 'VOLUNTEER_ANSWERED' && $e['vol'] !== null ? ['vol' => $e['vol'], 't' => $e['t']] : null,
                $all
            ))),
            'answer_status' => $answerStatus,
            'answered' => $answered,
            'conversation' => $best['conversation'] ?? null,
            'answered_by' => $best['vol'] ?? null,
            'answered_by_source' => $best['vol_source'] ?? null,
            'answer_source' => $best !== null ? $joinSource : null,
            'category' => $category,
            'outcome' => $outcome,
            'interactions' => $interactions,
            'timeline' => $timeline,
        ];
    }

    /**
     * Conversation length for one volunteer join: from join time to the earliest hang-up signal.
     *
     * @param array<string, mixed> $join
     * @param array<int, array<string, mixed>> $all
     * @param string[] $callerLegs
     * @param array<string, array<string, mixed>> $recordsByLeg
     * @param array<string, mixed>|null $rootRecord
     */
    private function conversationSeconds(array $join, array $all, array $callerLegs, array $recordsByLeg, ?array $rootRecord): ?int
    {
        $t0 = (int) $join['t'];
        $ends = [];
        $legRecord = $recordsByLeg[$join['leg']] ?? null;
        if ($join['own_leg'] && $legRecord !== null && $legRecord['end'] !== null) {
            $ends[] = (int) $legRecord['end'];
        }
        foreach ($all as $e) {
            if ($e['t'] < $t0) {
                continue;
            }
            $sameLeg = $e['cs'] === $join['leg'];
            $callerSide = in_array($e['cs'], $callerLegs, true);
            if ($e['name'] === 'VOLUNTEER_HUP' && ($sameLeg || $callerSide)) {
                $ends[] = $e['t'];
            } elseif ($e['name'] === 'CALLER_HUP') {
                $ends[] = $e['t'];
            } elseif ($e['name'] === 'VOLUNTEER_ANSWERED_BUT_CALLER_HUP' && ($sameLeg || $callerSide)) {
                $ends[] = $e['t'];
            }
        }
        if ($rootRecord !== null && $rootRecord['end'] !== null) {
            $ends[] = (int) $rootRecord['end'];
        }
        $ends = array_filter($ends, static fn (int $t): bool => $t >= $t0);
        if ($ends === []) {
            return null;
        }
        return min($ends) - $t0;
    }

    /** @param array<int, array<string, mixed>> $legEvents */
    private function legVolunteer(array $legEvents): ?string
    {
        foreach ($legEvents as $e) {
            if ($e['vol'] !== null) {
                return $e['vol'];
            }
        }
        return null;
    }

    /** Most recent volunteer dialed before the join (sequential routing). */
    private function inferVolunteer(array $all, int $joinTime): ?string
    {
        $found = null;
        foreach ($all as $e) {
            if ($e['t'] > $joinTime) {
                break;
            }
            if ($e['vol'] !== null && in_array($e['name'], ['VOLUNTEER_DIALED', 'VOLUNTEER_ANSWERED', 'VOLUNTEER_IN_CONFERENCE'], true) && $joinTime - $e['t'] <= 300) {
                $found = $e['vol'];
            }
        }
        return $found;
    }

    /**
     * @param array<int, array<string, mixed>> $all
     * @return array{0: ?int, 1: ?string}
     */
    private function attribute(array $all, ?int $lineSb): array
    {
        $withSb = array_values(array_filter($all, static fn (array $e): bool => $e['sb'] !== null && $e['sb'] > 0));
        if ($this->attributionPolicy === 'first' && $withSb !== []) {
            return [$withSb[0]['sb'], 'first_event'];
        }
        if ($this->attributionPolicy === 'last' && $withSb !== []) {
            return [$withSb[count($withSb) - 1]['sb'], 'last_event'];
        }
        if ($this->attributionPolicy === 'routing') {
            for ($i = count($withSb) - 1; $i >= 0; $i--) {
                if (in_array($withSb[$i]['name'], self::ROUTING_EVENTS, true)) {
                    return [$withSb[$i]['sb'], 'routing_event'];
                }
            }
            if ($withSb !== []) {
                return [$withSb[0]['sb'], 'first_event'];
            }
        }
        if ($lineSb !== null) {
            return [$lineSb, 'line_mapping'];
        }
        return [null, null];
    }

    private function add(string $key): void
    {
        if (!isset($this->parent[$key])) {
            $this->parent[$key] = $key;
        }
    }

    private function find(string $key): string
    {
        $root = $key;
        while ($this->parent[$root] !== $root) {
            $root = $this->parent[$root];
        }
        while ($this->parent[$key] !== $root) {
            $next = $this->parent[$key];
            $this->parent[$key] = $root;
            $key = $next;
        }
        return $root;
    }

    private function union(string $a, string $b): void
    {
        $ra = $this->find($a);
        $rb = $this->find($b);
        if ($ra !== $rb) {
            $this->parent[$rb] = $ra;
        }
    }
}
