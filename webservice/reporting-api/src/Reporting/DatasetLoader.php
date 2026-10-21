<?php

declare(strict_types=1);

namespace YapReporting\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use YapReporting\Config;
use YapReporting\Database\Connection;
use YapReporting\Http\HttpException;
use YapReporting\Privacy\Pseudonymizer;
use YapReporting\Yap\EventCatalog;
use YapReporting\Yap\SchemaInspector;

/**
 * Pulls the minimum set of columns needed for a reporting window and hands them to SessionBuilder.
 *
 * Privacy by construction:
 *  - records.from_number (the caller) is NEVER selected.
 *  - records_events.meta is selected only for volunteer-dial events; only the dialed volunteer
 *    number is extracted (then normalised) and the rest of the JSON is discarded immediately.
 *  - records.payload (if read) is parsed in memory for the Twilio Direction field only.
 *
 * The window is padded by 6 hours on each side so calls that straddle a boundary are grouped
 * correctly; SessionBuilder then keeps only calls that STARTED inside the range.
 */
final class DatasetLoader
{
    private const PAD_SECONDS = 21600;

    private int $participantOffset = 0;

    private DateTimeZone $storageTz;

    public function __construct(
        private readonly Connection $db,
        private readonly SchemaInspector $schema,
        private readonly EventCatalog $catalog,
        private readonly Config $config,
    ) {
        $this->storageTz = new DateTimeZone((string) $config->get('storage_timezone', 'UTC'));
    }

    /** @return array<string, mixed> */
    public function load(DateRange $range): array
    {
        if (!$this->schema->coreUsable()) {
            throw new HttpException(503, 'schema_unsupported', 'The YAP database schema is not supported by this reporting service.');
        }
        $from = $range->startTs() - self::PAD_SECONDS;
        $to = $range->endTs() + self::PAD_SECONDS;

        $events = $this->loadEvents($from, $to);
        $participants = $this->loadParticipants($from, $to, $events);
        $records = $this->loadRecords($from, $to);
        $links = $this->loadLinks($events, $records);

        $builder = new SessionBuilder(
            $this->config->int('answered_min_seconds', 5),
            $this->config->int('yap_schema.caller_role_value', 1),
            $this->config->int('yap_schema.volunteer_role_value', 2),
            (string) $this->config->get('attribution.policy', 'routing'),
            $this->lineMapping(),
            self::answeredRule($this->config),
        );
        $built = $builder->build($events, $participants, $records, $range->startTs(), $range->endTs(), $links);
        $built['sources'] = [
            'records' => $records !== [],
            'conference_participants' => $participants !== [],
            'cache_records_conference_participants' => $links !== [],
        ];
        $built['generated_at'] = time();
        $built['answered_rule'] = self::answeredRule($this->config);
        $built['diagnostics']['participant_clock_offset_seconds'] = $this->participantOffset;
        return $built;
    }

    /** @return array<int, array{cs: string, t: int, eid: int, name: ?string, sb: ?int, vol: ?string}> */
    private function loadEvents(int $from, int $to): array
    {
        $timeCol = 'event_time';
        $sbExpr = $this->schema->has('records_events', 'service_body_id') ? 'service_body_id' : 'NULL';

        $volunteerEventIds = [];
        foreach (['VOLUNTEER_DIALED', 'VOLUNTEER_ANSWERED', 'VOLUNTEER_IN_CONFERENCE', 'VOLUNTEER_NOANSWER', 'VOLUNTEER_REJECTED', 'VOLUNTEER_NUMBER_BUSY', 'VOLUNTEER_NUMBER_BAD', 'VOLUNTEER_ANSWERED_BUT_CALLER_HUP'] as $n) {
            $id = $this->catalog->id($n);
            if ($id !== null) {
                $volunteerEventIds[] = $id;
            }
        }
        $params = [];
        if ($this->schema->has('records_events', 'meta') && $volunteerEventIds !== []) {
            $metaExpr = 'CASE WHEN event_id IN (' . implode(',', array_fill(0, count($volunteerEventIds), '?')) . ') THEN meta ELSE NULL END';
            $params = $volunteerEventIds;
        } else {
            $metaExpr = 'NULL';
        }
        $params[] = $this->fmt($from);
        $params[] = $this->fmt($to);
        [$idSql, $idParams] = $this->idRange('records_events', $timeCol, $from, $to);
        if ($idSql === null) {
            return [];
        }
        $params = array_merge($params, $idParams);

        $typeExpr = $this->schema->has('records_events', 'type') ? 'type' : 'NULL';
        $sql = 'SELECT callsid, event_id, ' . $timeCol . ' AS t, ' . $sbExpr . ' AS sb, ' . $metaExpr . ' AS meta, ' . $typeExpr . ' AS etype'
            . ' FROM records_events WHERE ' . $timeCol . ' >= ? AND ' . $timeCol . ' < ?' . $idSql
            . ' ORDER BY ' . $timeCol;

        $max = $this->config->int('limits.max_event_rows', 750000);
        $metaKeys = (array) $this->config->get('yap_schema.volunteer_meta_keys', []);
        $out = [];
        foreach ($this->db->select($sql, $params) as $row) {
            if (count($out) >= $max) {
                throw new HttpException(413, 'range_too_large', 'Too much activity in this range. Request a shorter period.');
            }
            $cs = (string) ($row['callsid'] ?? '');
            $t = $this->ts($row['t'] ?? null);
            if ($cs === '' || $t === null) {
                continue;
            }
            $eid = (int) $row['event_id'];
            $out[] = [
                'cs' => $cs,
                't' => $t,
                'eid' => $eid,
                'name' => $this->catalog->name($eid),
                'sb' => isset($row['sb']) && is_numeric($row['sb']) ? (int) $row['sb'] : null,
                'vol' => $row['meta'] !== null ? self::volunteerFromMeta((string) $row['meta'], $metaKeys) : null,
                'keyed' => $row['meta'] !== null ? self::keyPressed((string) $row['meta']) : null,
                // Verified in production: type 2 rows and SM/MM message ids are text messages, even for events
                // such as MEETING_SEARCH_LOCATION_GATHERED that are not named *_SMS.
                'sms' => (isset($row['etype']) && (int) $row['etype'] === 2) || preg_match('/^(SM|MM)[0-9a-f]{32}$/', $cs) === 1,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array{cs: string, conf: string, role: ?int, t: ?int}>
     */
    private function loadParticipants(int $from, int $to, array $events): array
    {
        if (!$this->schema->has('conference_participants', 'callsid') || !$this->schema->has('conference_participants', 'conferencesid')) {
            return [];
        }
        $roleExpr = $this->schema->has('conference_participants', 'role') ? 'role' : 'NULL';
        $timeCol = $this->schema->firstOf('conference_participants', ['timestamp', 'created_at']);
        $timeExpr = $timeCol !== null ? '`' . $timeCol . '`' : 'NULL';
        $base = 'SELECT callsid, conferencesid, ' . $roleExpr . ' AS role, ' . $timeExpr . ' AS t FROM conference_participants';

        // Find conferences through the call ids already in the window, then every leg of those conferences.
        // Both columns are indexed, and this avoids comparing conference_participants.timestamp (filled by the
        // database clock) with records_events.event_time (filled by YAP), which can differ by the server offset.
        $ids = array_values(array_unique(array_map(static fn (array $e): string => $e['cs'], $events)));
        $conferences = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $sql = 'SELECT DISTINCT conferencesid FROM conference_participants WHERE callsid IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            foreach ($this->db->select($sql, $chunk) as $row) {
                if (!empty($row['conferencesid'])) {
                    $conferences[(string) $row['conferencesid']] = true;
                }
            }
        }
        $rows = [];
        foreach (array_chunk(array_keys($conferences), 400) as $chunk) {
            $chunk = array_map('strval', $chunk);
            foreach ($this->db->select($base . ' WHERE conferencesid IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) {
                $rows[] = $row;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $cs = (string) ($row['callsid'] ?? '');
            $conf = (string) ($row['conferencesid'] ?? '');
            if ($cs === '' || $conf === '') {
                continue;
            }
            $out[] = [
                'cs' => $cs,
                'conf' => $conf,
                'role' => isset($row['role']) && is_numeric($row['role']) ? (int) $row['role'] : null,
                't' => $this->ts($row['t'] ?? null),
            ];
        }
        $this->participantOffset = self::participantClockOffset($events, $out);
        if ($this->participantOffset !== 0) {
            foreach ($out as &$p) {
                if ($p['t'] !== null) {
                    $p['t'] -= $this->participantOffset;
                }
            }
            unset($p);
        }
        return $out;
    }

    /**
     * conference_participants.timestamp is filled by the database clock while YAP writes event times itself, so
     * on some hosts they differ by the server's UTC offset (seen in production: exactly 4 hours). Pair each
     * caller's join with its CALLER_IN_CONFERENCE event and each volunteer leg's first join with its
     * VOLUNTEER_ANSWERED event, take the median difference and round it to 15 minutes.
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<int, array<string, mixed>> $participants
     */
    public static function participantClockOffset(array $events, array $participants): int
    {
        $firstJoin = [];
        foreach ($participants as $p) {
            if ($p['t'] === null) {
                continue;
            }
            $key = $p['cs'] . '|' . (int) $p['role'];
            if (!isset($firstJoin[$key]) || $p['t'] < $firstJoin[$key]) {
                $firstJoin[$key] = $p['t'];
            }
        }
        $diffs = [];
        $seen = [];
        foreach ($events as $e) {
            $role = match ($e['name'] ?? null) {
                'CALLER_IN_CONFERENCE' => 1,
                'VOLUNTEER_ANSWERED' => 2,
                default => null,
            };
            if ($role === null) {
                continue;
            }
            $key = $e['cs'] . '|' . $role;
            if (isset($firstJoin[$key]) && !isset($seen[$key])) {
                $seen[$key] = true;
                $diffs[] = $firstJoin[$key] - $e['t'];
            }
        }
        if (count($diffs) < 2) {
            return 0;
        }
        sort($diffs);
        $median = $diffs[intdiv(count($diffs), 2)];
        $rounded = (int) (round($median / 900) * 900);
        return abs($rounded) >= 900 ? $rounded : 0;
    }

    /** @return array<int, array{cs: string, start: ?int, end: ?int, dur: ?int, line: ?string, dir: ?string}> */
    private function loadRecords(int $from, int $to): array
    {
        if (!$this->schema->has('records', 'callsid') || !$this->schema->has('records', 'start_time')) {
            return [];
        }
        $cols = ['callsid', 'start_time'];
        foreach (['end_time', 'duration', 'to_number'] as $c) {
            $cols[] = $this->schema->has('records', $c) ? $c : 'NULL AS ' . $c;
        }
        $readPayload = (bool) $this->config->get('yap_schema.read_direction_from_payload', true) && $this->schema->has('records', 'payload');
        $cols[] = $readPayload ? 'payload' : 'NULL AS payload';
        $cols[] = $this->schema->has('records', 'type') ? 'type AS rtype' : 'NULL AS rtype';

        [$idSql, $idParams] = $this->idRange('records', 'start_time', $from, $to);
        if ($idSql === null) {
            return [];
        }
        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM records WHERE start_time >= ? AND start_time < ?' . $idSql;
        $out = [];
        foreach ($this->db->select($sql, array_merge([$this->fmt($from), $this->fmt($to)], $idParams)) as $row) {
            $cs = (string) ($row['callsid'] ?? '');
            if ($cs === '') {
                continue;
            }
            // YAP's smsGateway() writes a records row for every inbound text message (type 2, the SmsSid as callsid).
            // A text is not a call: counting it made every message a "call that ended before a selection".
            if ((isset($row['rtype']) && (int) $row['rtype'] === 2) || preg_match('/^(SM|MM)[0-9a-f]{32}$/', $cs) === 1) {
                continue;
            }
            $out[] = [
                'cs' => $cs,
                'start' => $this->ts($row['start_time'] ?? null),
                'end' => $this->ts($row['end_time'] ?? null),
                'dur' => isset($row['duration']) && is_numeric($row['duration']) ? (int) $row['duration'] : null,
                'line' => Pseudonymizer::normaliseNumber(isset($row['to_number']) ? (string) $row['to_number'] : null),
                'dir' => $row['payload'] !== null ? self::directionFromPayload((string) $row['payload']) : null,
            ];
            // $row (and the payload it held) goes out of scope here.
        }
        return $out;
    }

    /**
     * YAP's cache_records_conference_participants maps child legs to the parent call. It has no
     * time column, so rows are fetched for the CallSids already in the window (both columns are indexed).
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array{parent: string, child: string, sb: ?int}>
     */
    private function loadLinks(array $events, array $records): array
    {
        $table = 'cache_records_conference_participants';
        if (!$this->schema->has($table, 'parent_callsid') || !$this->schema->has($table, 'callsid')) {
            return [];
        }
        $sbExpr = $this->schema->has($table, 'service_body_id') ? 'service_body_id' : 'NULL';
        $ids = [];
        foreach ($events as $e) {
            $ids[$e['cs']] = true;
        }
        foreach ($records as $r) {
            $ids[$r['cs']] = true;
        }
        $ids = array_map('strval', array_keys($ids));
        $out = [];
        $seen = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT parent_callsid, callsid, ' . $sbExpr . ' AS sb FROM ' . $table
                . ' WHERE parent_callsid IN (' . $in . ') OR callsid IN (' . $in . ')';
            foreach ($this->db->select($sql, array_merge($chunk, $chunk)) as $row) {
                $parent = (string) ($row['parent_callsid'] ?? '');
                $child = (string) ($row['callsid'] ?? '');
                $key = $parent . '|' . $child;
                if ($parent === '' || $child === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['parent' => $parent, 'child' => $child, 'sb' => isset($row['sb']) && is_numeric($row['sb']) ? (int) $row['sb'] : null];
            }
        }
        return $out;
    }

    /**
     * For VOLUNTEER_REJECTED YAP stores the key the volunteer pressed in meta.digits. An empty value means the
     * phone was picked up but nothing was pressed — typical of a carrier voicemail or call-forwarding answering.
     * Returns true / false, or null when the event has no digits field. Only presence is kept, never the key.
     */
    public static function keyPressed(string $meta): ?bool
    {
        $d = json_decode($meta, true);
        if (is_string($d)) {
            $d = json_decode($d, true);
        }
        if (!is_array($d) || !array_key_exists('digits', $d)) {
            return null;
        }
        return trim((string) $d['digits']) !== '';
    }

    public static function answeredRule(Config $config): string
    {
        return $config->get('answered_rule', 'accepted') === 'min_seconds' ? 'min_seconds' : 'accepted';
    }

    public static function answeredDefinition(Config $config): string
    {
        return self::answeredRule($config) === 'min_seconds'
            ? sprintf('A volunteer accepted the call and was connected for more than %d seconds.', $config->int('answered_min_seconds', 5))
            : 'A volunteer answered, accepted the call and was connected to the caller (as in YAP).';
    }

    /** @param array<int, mixed> $keys */
    public static function volunteerFromMeta(string $meta, array $keys): ?string
    {
        $decoded = json_decode($meta, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true); // tolerate double-encoded JSON
        }
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($keys as $k) {
            if (is_string($k) && isset($decoded[$k]) && is_scalar($decoded[$k])) {
                return Pseudonymizer::normaliseNumber((string) $decoded[$k]);
            }
        }
        return null;
    }

    public static function directionFromPayload(string $payload): ?string
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            parse_str($payload, $data);
        }
        $dir = $data['Direction'] ?? $data['direction'] ?? null;
        return is_string($dir) && preg_match('/^[a-z\-]{3,32}$/i', $dir) === 1 ? strtolower($dir) : null;
    }

    /**
     * YAP has no index on its time columns, so a plain time-range filter scans the whole table.
     * Rows are inserted in time order with auto-increment ids, so the id boundaries for a time
     * window can be found with a binary search of primary-key lookups (~30 indexed reads), and the
     * main query can then use the primary key. The time filter is still applied, and the window is
     * already padded by 6 hours, so slightly out-of-order rows are not lost.
     *
     * @return array{0: ?string, 1: array<int, int>} [extra SQL or '' for "no restriction", params];
     *         [null, []] means the window is empty.
     */
    private function idRange(string $table, string $timeExpr, int $from, int $to): array
    {
        if (!(bool) $this->config->get('use_id_range', true) || !$this->schema->has($table, 'id')) {
            return ['', []];
        }
        $bounds = $this->db->selectAll('SELECT MIN(id) AS lo, MAX(id) AS hi FROM ' . $table);
        if ($bounds === [] || $bounds[0]['lo'] === null) {
            return [null, []];
        }
        $min = (int) $bounds[0]['lo'];
        $max = (int) $bounds[0]['hi'];
        $first = $this->firstIdAtOrAfter($table, $timeExpr, $from, $min, $max);
        if ($first === null) {
            return [null, []];
        }
        $end = $this->firstIdAtOrAfter($table, $timeExpr, $to, $first, $max);
        return $end === null
            ? [' AND id >= ?', [$first]]
            : [' AND id >= ? AND id < ?', [$first, $end]];
    }

    /** Smallest id whose time is >= $ts, assuming ids grow with time. */
    private function firstIdAtOrAfter(string $table, string $timeExpr, int $ts, int $lo, int $hi): ?int
    {
        $target = $this->fmt($ts);
        $sql = 'SELECT id, ' . $timeExpr . ' AS t FROM ' . $table . ' WHERE id >= ? AND ' . $timeExpr . ' IS NOT NULL ORDER BY id LIMIT 1';
        $answer = null;
        $guard = 0;
        while ($lo <= $hi && $guard++ < 64) {
            $mid = intdiv($lo + $hi, 2);
            $row = $this->db->selectAll($sql, [$mid]);
            if ($row === [] || $row[0]['t'] === null) {
                $hi = $mid - 1;
                continue;
            }
            $id = (int) $row[0]['id'];
            if ((string) $row[0]['t'] >= $target) {
                $answer = $id;
                $hi = $mid - 1;
            } else {
                $lo = max($mid + 1, $id + 1);
            }
        }
        return $answer;
    }

    /** @return array<string, int> */
    private function lineMapping(): array
    {
        $out = [];
        foreach ((array) $this->config->get('attribution.line_mapping', []) as $number => $sb) {
            $n = Pseudonymizer::normaliseNumber((string) $number);
            if ($n !== null && is_numeric($sb) && (int) $sb > 0) {
                $out[$n] = (int) $sb;
            }
        }
        return $out;
    }

    private function fmt(int $ts): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone($this->storageTz)->format('Y-m-d H:i:s');
    }

    private function ts(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        $s = (string) $value;
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($s, 0, 19), $this->storageTz);
        if ($d !== false) {
            return $d->getTimestamp();
        }
        try {
            return (new DateTimeImmutable($s, $this->storageTz))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
