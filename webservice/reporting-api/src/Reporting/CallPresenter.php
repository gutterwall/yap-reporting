<?php

declare(strict_types=1);

namespace YapReporting\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use YapReporting\Privacy\Pseudonymizer;

/**
 * Converts internal session arrays into API output using an explicit field allowlist.
 * Raw CallSids, phone numbers, PINs, recording URLs and event meta are never emitted.
 */
final class CallPresenter
{
    public const EVENT_LABELS = [
        'VOLUNTEER_SEARCH' => 'Caller asked for a volunteer',
        'VOLUNTEER_SEARCH_FORCE_DIALED' => 'Caller sent straight to volunteers',
        'VOLUNTEER_NUMBER_BUSY' => 'Volunteer line busy',
        'VOLUNTEER_NUMBER_BAD' => 'Volunteer number failed',
        'STIR_VERSTAT_RECEIVED' => 'Caller ID verification received',
        'HELPLINE_ROUTE' => 'Forwarded to helpline number',
        'VOLUNTEER_DIALED' => 'Volunteer dialed',
        'VOLUNTEER_ANSWERED' => 'Volunteer picked up',
        'VOLUNTEER_REJECTED' => 'Volunteer declined',
        'VOLUNTEER_NOANSWER' => 'Volunteer did not answer',
        'VOLUNTEER_ANSWERED_BUT_CALLER_HUP' => 'Volunteer answered after caller hung up',
        'CALLER_IN_CONFERENCE' => 'Caller waiting for volunteer',
        'VOLUNTEER_IN_CONFERENCE' => 'Volunteer connected',
        'VOLUNTEER_HUP' => 'Volunteer hung up',
        'CALLER_HUP' => 'Caller hung up',
        'VOICEMAIL' => 'Voicemail left',
        'VOICEMAIL_PLAYBACK' => 'Voicemail playback',
        'MEETING_SEARCH' => 'Meeting search',
        'MEETING_SEARCH_LOCATION_GATHERED' => 'Meeting search location provided',
        'JFT_LOOKUP' => 'Just for Today played',
        'SPAD_LOOKUP' => 'Spiritual Principle a Day played',
        'DIALBACK' => 'Dialback',
        'PROVINCE_LOOKUP_LIST' => 'State/province list',
    ];

    public function __construct(
        private readonly Pseudonymizer $pseudo,
        private readonly DateTimeZone $tz,
        private readonly bool $showVolunteers,
        /** @var (callable(string): ?string)|null */
        private readonly mixed $nameFor,
    ) {
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    public function summary(array $s): array
    {
        $local = (new DateTimeImmutable('@' . $s['start']))->setTimezone($this->tz);
        $row = [
            'id' => $this->pseudo->callId((string) $s['root'], $local->format('Ymd')),
            'started_at' => $local->format(DATE_ATOM),
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('H:i:s'),
            'service_body_id' => $s['sb'],
            'attribution' => $s['sb_source'] ?? 'unassigned',
            'category' => $s['category'],
            'outcome' => $s['outcome'],
            'volunteer_requested' => $s['requested'],
            'routed_to_volunteers' => $s['routed'],
            'volunteer_attempts' => $s['attempts'],
            'answered' => $s['answered'],
            'answer_status' => $s['answer_status'],
            'duration_seconds' => $s['dur'],
            'conversation_seconds' => $s['conversation'],
            'voicemail' => $s['voicemail'],
            'meeting_lookups' => $s['meeting'],
            'jft' => $s['jft'],
            'spad' => $s['spad'],
        ];
        if ($this->showVolunteers) {
            $row['answered_by'] = $this->volunteer($s['answered_by'] ?? null);
        }
        return $row;
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    public function detail(array $s): array
    {
        $out = $this->summary($s);
        $start = (int) $s['start'];
        $events = [];
        foreach ($s['timeline'] as $e) {
            $local = (new DateTimeImmutable('@' . $e['t']))->setTimezone($this->tz);
            $item = [
                'at' => $local->format(DATE_ATOM),
                'offset_seconds' => $e['t'] - $start,
                'event' => $e['name'] ?? ('UNKNOWN_EVENT_' . (int) $e['eid']),
                'label' => self::EVENT_LABELS[$e['name'] ?? ''] ?? 'Other event',
                'leg' => $e['leg'],
                'service_body_id' => $e['sb'],
            ];
            if ($this->showVolunteers && $e['vol'] !== null) {
                $item['volunteer'] = $this->volunteer($e['vol']);
            }
            $events[] = $item;
        }
        $out['legs'] = $s['legs'];
        $out['events'] = $events;
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function volunteer(?string $number): ?array
    {
        if ($number === null) {
            return null;
        }
        $v = ['volunteer_id' => $this->pseudo->volunteerId($number)];
        if (is_callable($this->nameFor)) {
            $v['name'] = ($this->nameFor)($number);
        }
        return $v;
    }
}
