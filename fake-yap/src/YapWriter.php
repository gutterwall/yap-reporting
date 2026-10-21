<?php

declare(strict_types=1);

namespace FakeYap;

use PDO;

/**
 * Writes rows the way YAP 4.5.2 does (app/Repositories/ReportsRepository.php and the controllers that call it):
 *
 *  - records_events.event_time and records.start_time / end_time are UTC strings (YAP calls
 *    date_default_timezone_set('UTC') / gmdate()) stored through the connection's DEFAULT time zone,
 *    because YAP never sets one. This writer does not set one either.
 *  - conference_participants.timestamp is filled by the database clock (useCurrent()), so on a server whose
 *    default zone is not UTC it is that zone's local time. FROM_UNIXTIME() gives exactly what
 *    CURRENT_TIMESTAMP would have stored at that instant.
 *  - meta is json_encode() of the object YAP passes; records.payload is json_encode() of the Twilio request.
 *
 * Rows are queued and written in the order YAP would have written them (by the time of the request that wrote
 * them: a call's records row is written by the status callback when the call ends), so auto-increment ids follow
 * insertion time as in production, including overlapping calls.
 */
final class YapWriter
{
    /** @var array<int, array{at: int, seq: int, sql: string, params: array<int, mixed>}> */
    private array $queue = [];

    private int $seq = 0;

    /** @param array<string, int> $eventIds name => id, from YAP's App\Constants\EventId */
    public function __construct(private readonly PDO $pdo, private readonly array $eventIds)
    {
    }

    /** @return array<string, int> */
    public static function eventIds(string $eventIdFile): array
    {
        $src = (string) file_get_contents($eventIdFile);
        preg_match_all('/const\s+([A-Z_]+)\s*=\s*(\d+)\s*;/', $src, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] = (int) $row[2];
        }
        return $out;
    }

    public function id(string $name): int
    {
        if (!isset($this->eventIds[$name])) {
            throw new \InvalidArgumentException('Unknown YAP event ' . $name);
        }
        return $this->eventIds[$name];
    }

    /** CallService::insertCallEventRecord(): type 1 for CallSid requests, 2 for SmsSid requests. */
    public function event(string $sid, string $name, int $at, ?int $serviceBodyId, ?array $meta = null, ?int $type = null): void
    {
        $type ??= str_starts_with($sid, 'SM') ? 2 : 1;
        $this->push($at, 'INSERT INTO records_events (callsid, event_time, event_id, service_body_id, meta, type) VALUES (?, ?, ?, ?, ?, ?)', [
            $sid, gmdate('Y-m-d H:i:s', $at), $this->id($name), $serviceBodyId, $meta === null ? null : json_encode($meta), $type,
        ]);
    }

    /**
     * CallFlowController::statusCallback() (voice, written when the call ends) and ::smsGateway() (text, written on arrival).
     *
     * @param array<string, mixed> $payload the Twilio request parameters
     */
    public function record(string $sid, int $start, int $end, string $from, string $to, int $duration, array $payload, int $type = 1): void
    {
        $this->push($end, 'INSERT INTO records (callsid, start_time, end_time, from_number, to_number, payload, duration, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $sid, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end), $from, $to, json_encode($payload), $duration, $type,
        ]);
    }

    /** CallService::setConferenceParticipant(): the timestamp column defaults to the database clock. */
    public function participant(string $conferenceSid, string $callSid, string $friendlyName, int $role, int $at): void
    {
        $this->push($at, 'INSERT INTO conference_participants (`timestamp`, conferencesid, callsid, friendlyname, role) VALUES (FROM_UNIXTIME(?), ?, ?, ?, ?)', [
            $at, $conferenceSid, $callSid, $friendlyName, $role,
        ]);
    }

    /** What ReportsRepository::getCallRecords() leaves behind when a report request dies before its DELETE. */
    public function legCache(string $parent, string $child, ?int $serviceBodyId, string $guid): void
    {
        $this->push(0, 'INSERT INTO cache_records_conference_participants (parent_callsid, callsid, guid, service_body_id) VALUES (?, ?, ?, ?)', [
            $parent, $child, $guid, $serviceBodyId,
        ]);
    }

    /**
     * A volunteer list in YAP's config table (App\Constants\DataType::YAP_VOLUNTEERS_V2 or YAP_GROUP_VOLUNTEERS_V2).
     *
     * @param array<int, array<string, mixed>> $volunteers VolunteerData objects
     */
    public function volunteers(int $serviceBodyId, array $volunteers, string $dataType = '_YAP_VOLUNTEERS_V2_', ?int $parentId = null, ?int $status = null): void
    {
        $this->push(0, 'INSERT INTO config (service_body_id, data, data_type, parent_id, status) VALUES (?, ?, ?, ?, ?)', [
            $serviceBodyId, json_encode($volunteers), $dataType, $parentId, $status,
        ]);
    }

    /** Rows in tables the reporting service must never read. Their values are recognisable in any output. */
    public function canaries(): void
    {
        $this->push(0, 'INSERT INTO users (name, username, password, permissions, is_admin, service_bodies) VALUES (?, ?, ?, ?, ?, ?)', [
            'CANARY-USER-NAME', 'canary_admin', '$2y$10$CANARYPASSWORDHASHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 1, 1, '1001,1002',
        ]);
        $this->push(0, 'INSERT INTO sessions (callsid, pin) VALUES (?, ?)', ['CACANARY0000000000000000000000000', 7777777]);
        $this->push(0, 'INSERT INTO alerts (`timestamp`, alert_id, payload, status) VALUES (UTC_TIMESTAMP(), 1, ?, 0)', ['CANARY-ALERT-+15555550999']);
        $this->push(0, 'INSERT INTO metrics (data, service_body_id) VALUES (?, 1001)', ['{"canary":"CANARY-METRIC"}']);
        $this->push(0, "INSERT INTO cache (`key`, value, expiry) VALUES ('CANARY-CACHE', 'CANARY-CACHE-VALUE', 0)", []);
        $this->push(0, "INSERT INTO migrations (version) VALUES ('4.5.2')", []);
        $this->push(0, "INSERT INTO flags (flag_name, flag_setting) VALUES ('canary_flag', 1)", []);
    }

    /** Write everything queued, in YAP's insertion order. Returns the number of rows. */
    public function flush(): int
    {
        usort($this->queue, static fn (array $a, array $b): int => [$a['at'], $a['seq']] <=> [$b['at'], $b['seq']]);
        $statements = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($this->queue as $row) {
                $stmt = $statements[$row['sql']] ??= $this->pdo->prepare($row['sql']);
                $stmt->execute($row['params']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $n = count($this->queue);
        $this->queue = [];
        return $n;
    }

    /** @param array<int, mixed> $params */
    private function push(int $at, string $sql, array $params): void
    {
        $this->queue[] = ['at' => $at, 'seq' => $this->seq++, 'sql' => $sql, 'params' => $params];
    }
}
