<?php

declare(strict_types=1);

namespace YapReporting\Reporting;

use YapReporting\Yap\EventCatalog;
use YapReporting\Yap\SchemaInspector;

/**
 * Decides, from the installed YAP schema and event catalog, which metrics can be computed.
 * A metric that cannot be computed is returned as unavailable with a reason — never as zero.
 */
final class Capabilities
{
    /** @var array<string, ?string> requirement => reason (null = satisfied) */
    private array $cache = [];

    public function __construct(private readonly SchemaInspector $schema, private readonly EventCatalog $catalog)
    {
    }

    /** Returns null when available, otherwise a human-readable reason. */
    public function missing(string $requirement): ?string
    {
        if (array_key_exists($requirement, $this->cache)) {
            return $this->cache[$requirement];
        }
        return $this->cache[$requirement] = $this->evaluate($requirement);
    }

    public function available(string $requirement): bool
    {
        return $this->missing($requirement) === null;
    }

    /** @return array<string, array{available: bool, reason: ?string}> */
    public function matrix(): array
    {
        $out = [];
        foreach (array_keys(self::REQUIREMENTS) as $req) {
            $reason = $this->missing($req);
            $out[$req] = ['available' => $reason === null, 'reason' => $reason];
        }
        return $out;
    }

    /** requirement => description of what it unlocks */
    public const REQUIREMENTS = [
        'calls' => 'Unique incoming calls',
        'volunteer_requested' => 'Calls where the caller asked for a volunteer',
        'volunteer_routing' => 'Volunteer dial attempts and routed calls',
        'answered' => 'Answered calls (volunteer accepted and was connected)',
        'conversation_time' => 'Conversation time between volunteer and caller',
        'no_answer_attempts' => 'Volunteer attempts not answered',
        'rejected_attempts' => 'Volunteer attempts rejected',
        'busy_attempts' => 'Volunteer attempts that found the line busy',
        'helpline_forward' => 'Calls forwarded to an outside helpline number',
        'bad_number_attempts' => 'Volunteer attempts to a number that failed',
        'voicemail' => 'Voicemail',
        'menu_only' => 'Calls ending before a service was selected',
        'meeting_lookup_voice' => 'Meeting lookups by phone',
        'meeting_lookup_sms' => 'Meeting lookups by SMS',
        'volunteer_sms' => 'Volunteer requests by SMS',
        'jft_voice' => 'Just for Today by phone',
        'jft_sms' => 'Just for Today by SMS',
        'spad_voice' => 'Spiritual Principle a Day by phone',
        'spad_sms' => 'Spiritual Principle a Day by SMS',
        'call_durations' => 'Total call duration',
        'volunteer_attribution' => 'Per-volunteer statistics',
        'area_attribution' => 'Attribution of activity to service bodies',
        'multi_leg_grouping' => 'Grouping volunteer legs with the incoming call',
    ];

    private function evaluate(string $req): ?string
    {
        if (!$this->schema->coreUsable()) {
            return 'records_events table is missing required columns (callsid, event_id, event_time).';
        }
        if ($req !== 'calls' && $req !== 'call_durations' && $req !== 'multi_leg_grouping' && $req !== 'area_attribution' && $this->catalog->isEmpty()) {
            return 'YAP event definitions (app/Constants/EventId.php) were not found; set event_ids_file in reporting-config.php.';
        }
        $events = fn (string ...$names): ?string => $this->needEvents($names);
        return match ($req) {
            'calls' => null,
            'volunteer_requested' => $this->needAnyEvent(['VOLUNTEER_SEARCH', 'VOLUNTEER_SEARCH_FORCE_DIALED', 'HELPLINE_ROUTE', 'VOLUNTEER_DIALED']),
            'volunteer_routing' => $events('VOLUNTEER_DIALED'),
            'answered' => $this->answeredRequirement(),
            'conversation_time' => $this->conversationRequirement(),
            'no_answer_attempts' => $events('VOLUNTEER_NOANSWER'),
            'rejected_attempts' => $events('VOLUNTEER_REJECTED'),
            'busy_attempts' => $events('VOLUNTEER_NUMBER_BUSY'),
            'helpline_forward' => $events('HELPLINE_ROUTE', 'VOLUNTEER_DIALED'),
            'bad_number_attempts' => $events('VOLUNTEER_NUMBER_BAD'),
            'voicemail' => $events('VOICEMAIL'),
            'menu_only' => $events('VOLUNTEER_SEARCH', 'MEETING_SEARCH'),
            'meeting_lookup_voice' => $events('MEETING_SEARCH'),
            'meeting_lookup_sms' => $events('MEETING_SEARCH_SMS'),
            'volunteer_sms' => $events('VOLUNTEER_SEARCH_SMS'),
            'jft_voice' => $events('JFT_LOOKUP'),
            'jft_sms' => $events('JFT_LOOKUP_SMS'),
            'spad_voice' => $events('SPAD_LOOKUP'),
            'spad_sms' => $events('SPAD_LOOKUP_SMS'),
            'call_durations' => ($this->schema->has('records', 'duration') || ($this->schema->has('records', 'start_time') && $this->schema->has('records', 'end_time')))
                ? null : 'records table has no duration or start/end time columns (is the Twilio status callback configured?).',
            'volunteer_attribution' => ($this->schema->has('records_events', 'meta')
                ? $events('VOLUNTEER_DIALED') : 'records_events.meta column not present.'),
            'area_attribution' => ($this->schema->has('records_events', 'service_body_id') ? null : 'records_events.service_body_id column not present.'),
            'multi_leg_grouping' => ((($this->schema->has('conference_participants', 'conferencesid') && $this->schema->has('conference_participants', 'callsid'))
                || ($this->schema->has('cache_records_conference_participants', 'parent_callsid') && $this->schema->has('cache_records_conference_participants', 'callsid')))
                ? null : 'Neither conference_participants nor cache_records_conference_participants is available; volunteer legs cannot be linked to calls.'),
            default => 'Unknown requirement.',
        };
    }

    private function answeredRequirement(): ?string
    {
        if (!$this->catalog->has('VOLUNTEER_IN_CONFERENCE')) {
            return 'This YAP build does not define VOLUNTEER_IN_CONFERENCE, which records a volunteer accepting a call.';
        }
        return null;
    }

    private function conversationRequirement(): ?string
    {
        $join = $this->answeredRequirement();
        if ($join !== null) {
            return $join;
        }
        $end = $this->catalog->has('VOLUNTEER_HUP') || $this->catalog->has('CALLER_HUP') || $this->schema->has('records', 'end_time');
        return $end ? null : 'No hang-up signal (VOLUNTEER_HUP / CALLER_HUP events or records.end_time) to measure conversation length.';
    }

    /** @param string[] $names */
    private function needEvents(array $names): ?string
    {
        $missing = array_values(array_filter($names, fn (string $n): bool => !$this->catalog->has($n)));
        return $missing === [] ? null : 'This YAP build does not define event(s): ' . implode(', ', $missing) . '.';
    }

    /** @param string[] $names */
    private function needAnyEvent(array $names): ?string
    {
        foreach ($names as $n) {
            if ($this->catalog->has($n)) {
                return null;
            }
        }
        return 'This YAP build defines none of: ' . implode(', ', $names) . '.';
    }
}
