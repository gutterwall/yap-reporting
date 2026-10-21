<?php

declare(strict_types=1);

use YapReporting\App;
use YapReporting\Config;
use YapReporting\Database\Connection;
use YapReporting\Yap\EventCatalog;

/**
 * Builds a throwaway SQLite database shaped like YAP's reporting tables, populated with
 * FICTIONAL calls. Caller numbers use the 555-01xx fictional range.
 */
final class Fixture
{
    public const CALLER_NUMBER = '+15555550123';
    public const VOLUNTEER_A = '+15555550171';
    public const VOLUNTEER_B = '+15555550172';
    public const VOLUNTEER_C = '+15555550173';
    public const HELPLINE = '+15555550100';

    public static function catalog(): EventCatalog
    {
        return new EventCatalog(EventCatalog::parse((string) file_get_contents(__DIR__ . '/EventId.php')), 'fixture');
    }

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/yapr-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        return $dir;
    }

    /** @param array<string, mixed> $overrides */
    public static function config(string $dataDir, array $overrides = []): Config
    {
        return new Config(array_replace_recursive([
            'data_dir' => $dataDir,
            'default_timezone' => 'America/New_York',
            'storage_timezone' => 'UTC',
            'cache_ttl_seconds' => 0,
            'require_https' => true,
        ], $overrides), __DIR__);
    }

    public static function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE records_events (id INTEGER PRIMARY KEY, callsid TEXT, event_id INTEGER, event_time TEXT, service_body_id INTEGER, meta TEXT, type INTEGER)');
        $pdo->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, callsid TEXT, from_number TEXT, to_number TEXT, duration INTEGER, start_time TEXT, end_time TEXT, payload TEXT, type INTEGER)');
        $pdo->exec('CREATE TABLE conference_participants (id INTEGER PRIMARY KEY, timestamp TEXT, conferencesid TEXT, callsid TEXT, friendlyname TEXT, role INTEGER)');
        $pdo->exec('CREATE TABLE config (id INTEGER PRIMARY KEY, service_body_id INTEGER, data TEXT, data_type TEXT, parent_id INTEGER, status INTEGER)');
        $pdo->exec('CREATE TABLE cache_records_conference_participants (parent_callsid TEXT, callsid TEXT, guid TEXT, service_body_id INTEGER)');
        // Tables that exist in YAP but must never be read; seeded so a stray query would leak test data.
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, username TEXT, password TEXT)');
        $pdo->exec("INSERT INTO users (name, username, password) VALUES ('Admin Example', 'admin', '\$2y\$10\$fixturehash')");
        return $pdo;
    }

    public static function event(\PDO $pdo, string $cs, int $eventId, string $utc, ?int $sb, ?array $meta = null, ?int $type = null): void
    {
        $type ??= str_starts_with($cs, 'SM') ? 2 : 1;
        $st = $pdo->prepare('INSERT INTO records_events (callsid, event_id, event_time, service_body_id, meta, type) VALUES (?,?,?,?,?,?)');
        $st->execute([$cs, $eventId, $utc, $sb, $meta === null ? null : json_encode($meta), $type]);
    }

    public static function record(\PDO $pdo, string $cs, string $startUtc, string $endUtc, int $duration, string $direction = 'inbound'): void
    {
        $st = $pdo->prepare('INSERT INTO records (callsid, from_number, to_number, duration, start_time, end_time, payload) VALUES (?,?,?,?,?,?,?)');
        $payload = json_encode(['From' => self::CALLER_NUMBER, 'To' => self::HELPLINE, 'Direction' => $direction, 'CallSid' => $cs]);
        $st->execute([$cs, self::CALLER_NUMBER, self::HELPLINE, $duration, $startUtc, $endUtc, $payload]);
    }

    public static function participant(\PDO $pdo, string $cs, string $conf, int $role, string $utc): void
    {
        $st = $pdo->prepare('INSERT INTO conference_participants (timestamp, conferencesid, callsid, friendlyname, role) VALUES (?,?,?,?,?)');
        $st->execute([$utc, $conf, $cs, 'conf', $role]);
    }

    /**
     * Standard dataset on 2026-06-10 (UTC), Area 10 and Area 20:
     *  CA_ANS   Area 10: 3 volunteers dialed; B joins at 14:00:30, hangs up 14:02:30 → answered (120 s)
     *  CA_SHORT Area 10: volunteer joins, caller hangs up 4 s later → connected_short (not answered)
     *  CA_VM    Area 10: volunteer requested, nobody answers → voicemail
     *  CA_MTG   no service body on events, helpline line → meeting search ×2 + JFT
     *  CA_B20   Area 20: answered (60 s)
     *  SM_1     SMS meeting search, Area 10
     */
    public static function seed(\PDO $pdo, EventCatalog $c): void
    {
        $id = static fn (string $n): int => (int) $c->id($n);

        // Calls well outside the main test day (exercise the id-range narrowing).
        self::record($pdo, 'CA_OLD', '2026-05-01 12:00:00', '2026-05-01 12:01:00', 60);
        self::event($pdo, 'CA_OLD', $id('MEETING_SEARCH'), '2026-05-01 12:00:30', 10);

        // Answered multi-leg call in Area 10.
        self::record($pdo, 'CA_ANS', '2026-06-10 14:00:00', '2026-06-10 14:02:40', 160);
        self::event($pdo, 'CA_ANS', $id('VOLUNTEER_SEARCH'), '2026-06-10 14:00:05', 10);
        self::event($pdo, 'CA_ANS', $id('CALLER_IN_CONFERENCE'), '2026-06-10 14:00:06', 10);
        self::event($pdo, 'CA_ANS', $id('VOLUNTEER_DIALED'), '2026-06-10 14:00:07', 10, ['to_number' => self::VOLUNTEER_A]);
        self::event($pdo, 'CA_ANS_LEG_A', $id('VOLUNTEER_NOANSWER'), '2026-06-10 14:00:20', 10, ['to_number' => self::VOLUNTEER_A]);
        self::event($pdo, 'CA_ANS', $id('VOLUNTEER_DIALED'), '2026-06-10 14:00:21', 10, ['to_number' => self::VOLUNTEER_B]);
        self::participant($pdo, 'CA_ANS', 'CF_ANS', 1, '2026-06-10 14:00:06');
        self::participant($pdo, 'CA_ANS_LEG_B', 'CF_ANS', 2, '2026-06-10 14:00:30');
        self::event($pdo, 'CA_ANS_LEG_B', $id('VOLUNTEER_IN_CONFERENCE'), '2026-06-10 14:00:30', 10, ['to_number' => self::VOLUNTEER_B]);
        self::event($pdo, 'CA_ANS_LEG_B', $id('VOLUNTEER_HUP'), '2026-06-10 14:02:30', 10);
        self::record($pdo, 'CA_ANS_LEG_B', '2026-06-10 14:00:22', '2026-06-10 14:02:30', 128, 'outbound-api');

        // Volunteer connects for only 4 seconds.
        self::record($pdo, 'CA_SHORT', '2026-06-10 15:00:00', '2026-06-10 15:00:40', 40);
        self::event($pdo, 'CA_SHORT', $id('VOLUNTEER_SEARCH'), '2026-06-10 15:00:05', 10);
        self::event($pdo, 'CA_SHORT', $id('VOLUNTEER_DIALED'), '2026-06-10 15:00:07', 10, ['to_number' => self::VOLUNTEER_A]);
        self::participant($pdo, 'CA_SHORT', 'CF_SHORT', 1, '2026-06-10 15:00:06');
        self::participant($pdo, 'CA_SHORT_LEG', 'CF_SHORT', 2, '2026-06-10 15:00:28');
        self::event($pdo, 'CA_SHORT_LEG', $id('VOLUNTEER_IN_CONFERENCE'), '2026-06-10 15:00:30', 10, ['to_number' => self::VOLUNTEER_A]);
        self::event($pdo, 'CA_SHORT', $id('CALLER_HUP'), '2026-06-10 15:00:34', 10);

        // Voicemail.
        self::record($pdo, 'CA_VM', '2026-06-10 16:00:00', '2026-06-10 16:01:30', 90);
        self::event($pdo, 'CA_VM', $id('VOLUNTEER_SEARCH'), '2026-06-10 16:00:05', 10);
        self::event($pdo, 'CA_VM', $id('VOLUNTEER_DIALED'), '2026-06-10 16:00:07', 10, ['to_number' => self::VOLUNTEER_A]);
        self::event($pdo, 'CA_VM_LEG', $id('VOLUNTEER_NOANSWER'), '2026-06-10 16:00:30', 10, ['to_number' => self::VOLUNTEER_A]);
        self::event($pdo, 'CA_VM', $id('VOICEMAIL'), '2026-06-10 16:00:50', 10, ['url' => 'https://api.twilio.com/Recordings/RE0123', 'caller_number' => self::CALLER_NUMBER]);

        // Automated meeting search with no service body on the events.
        self::record($pdo, 'CA_MTG', '2026-06-10 17:00:00', '2026-06-10 17:02:00', 120);
        self::event($pdo, 'CA_MTG', $id('MEETING_SEARCH'), '2026-06-10 17:00:30', null);
        self::event($pdo, 'CA_MTG', $id('MEETING_SEARCH'), '2026-06-10 17:01:00', null);
        self::event($pdo, 'CA_MTG', $id('JFT_LOOKUP'), '2026-06-10 17:01:30', null);

        // Answered call in Area 20.
        self::record($pdo, 'CA_B20', '2026-06-10 18:00:00', '2026-06-10 18:01:30', 90);
        self::event($pdo, 'CA_B20', $id('VOLUNTEER_SEARCH'), '2026-06-10 18:00:05', 20);
        self::event($pdo, 'CA_B20', $id('VOLUNTEER_DIALED'), '2026-06-10 18:00:07', 20, ['to_number' => self::VOLUNTEER_A]);
        self::participant($pdo, 'CA_B20', 'CF_B20', 1, '2026-06-10 18:00:06');
        self::participant($pdo, 'CA_B20_LEG', 'CF_B20', 2, '2026-06-10 18:00:18');
        self::event($pdo, 'CA_B20_LEG', $id('VOLUNTEER_IN_CONFERENCE'), '2026-06-10 18:00:20', 20); // no number recorded
        self::event($pdo, 'CA_B20', $id('CALLER_HUP'), '2026-06-10 18:01:20', 20);

        // Linked only through YAP's own leg cache (no conference row): volunteer leg must not become a call.
        self::record($pdo, 'CA_LINK', '2026-06-10 20:00:00', '2026-06-10 20:03:00', 180);
        self::event($pdo, 'CA_LINK', $id('VOLUNTEER_SEARCH'), '2026-06-10 20:00:05', null);
        self::event($pdo, 'CA_LINK', $id('VOLUNTEER_DIALED'), '2026-06-10 20:00:07', null, ['to_number' => self::VOLUNTEER_B]);
        self::record($pdo, 'CA_LINK_LEG', '2026-06-10 20:00:08', '2026-06-10 20:02:50', 162, 'outbound-api');
        $pdo->exec("INSERT INTO cache_records_conference_participants (parent_callsid, callsid, guid, service_body_id) VALUES ('CA_LINK', 'CA_LINK', 'g1', 30), ('CA_LINK', 'CA_LINK_LEG', 'g1', 30)");

        // Force-dialed straight to volunteers; first volunteer busy, second number bad; caller gives up.
        self::record($pdo, 'CA_FORCE', '2026-06-10 21:00:00', '2026-06-10 21:01:00', 60);
        self::event($pdo, 'CA_FORCE', $id('STIR_VERSTAT_RECEIVED'), '2026-06-10 21:00:01', null);
        self::event($pdo, 'CA_FORCE', $id('VOLUNTEER_SEARCH_FORCE_DIALED'), '2026-06-10 21:00:02', 40);
        self::event($pdo, 'CA_FORCE', $id('VOLUNTEER_DIALED'), '2026-06-10 21:00:03', 40, ['to_number' => self::VOLUNTEER_A]);
        self::event($pdo, 'CA_FORCE_LEG1', $id('VOLUNTEER_NUMBER_BUSY'), '2026-06-10 21:00:05', 40, ['to_number' => self::VOLUNTEER_A]);
        self::event($pdo, 'CA_FORCE', $id('VOLUNTEER_DIALED'), '2026-06-10 21:00:06', 40, ['to_number' => self::VOLUNTEER_B]);
        self::event($pdo, 'CA_FORCE_LEG2', $id('VOLUNTEER_NUMBER_BAD'), '2026-06-10 21:00:07', 40, ['to_number' => self::VOLUNTEER_B]);
        self::event($pdo, 'CA_FORCE', $id('CALLER_HUP'), '2026-06-10 21:00:50', 40);

        // Area 50 forwards to an outside helpline number (observed in production data: events 1 then 15).
        self::record($pdo, 'CA_FWD', '2026-06-10 22:00:00', '2026-06-10 22:05:00', 300);
        self::event($pdo, 'CA_FWD', $id('VOLUNTEER_SEARCH'), '2026-06-10 22:00:10', 50);
        self::event($pdo, 'CA_FWD', $id('HELPLINE_ROUTE'), '2026-06-10 22:00:11', 50, ['helpline_number' => '5555550199', 'extension' => 'w']);

        // SMS meeting search. YAP's smsGateway() also writes a records row (type 2) for every inbound text: not a call.
        $pdo->prepare('INSERT INTO records (callsid, from_number, to_number, duration, start_time, end_time, payload, type) VALUES (?,?,?,?,?,?,?,2)')
            ->execute(['SM_1', self::CALLER_NUMBER, self::HELPLINE, 0, '2026-06-10 19:00:00', '2026-06-10 19:00:00', json_encode(['SmsSid' => 'SM_1', 'Body' => '00000'])]);
        self::event($pdo, 'SM_1', $id('MEETING_SEARCH_SMS'), '2026-06-10 19:00:00', 10);
        // Production logs MEETING_SEARCH_LOCATION_GATHERED for texts too (type 2); it must not become a "call".
        self::event($pdo, 'SM_1', $id('MEETING_SEARCH_LOCATION_GATHERED'), '2026-06-10 19:00:00', null, ['gather' => '00000'], 2);

        // 2026-06-11, Area 60: a group volunteer (C) whose phone picks up first every time but never accepts
        // (voicemail / forwarding), while A rings out. Each call ends in voicemail.
        foreach ([1, 2, 3] as $i) {
            $cs = 'CA_GRP' . $i;
            $t = static fn (int $sec): string => sprintf('2026-06-11 1%d:00:%02d', $i, $sec);
            self::record($pdo, $cs, $t(0), $t(50), 50);
            self::event($pdo, $cs, $id('VOLUNTEER_SEARCH'), $t(2), 60);
            self::event($pdo, $cs, $id('CALLER_IN_CONFERENCE'), $t(3), 60);
            self::event($pdo, $cs, $id('VOLUNTEER_DIALED'), $t(4), 60, ['to_number' => '5555550173']);
            self::event($pdo, $cs, $id('VOLUNTEER_DIALED'), $t(4), 60, ['to_number' => '5555550171']);
            self::event($pdo, $cs . '_C', $id('VOLUNTEER_ANSWERED'), $t(6), 60, ['to_number' => self::VOLUNTEER_C]);
            self::event($pdo, $cs . '_C', $id('VOLUNTEER_REJECTED'), $t(20), 60, ['digits' => '', 'to_number' => self::VOLUNTEER_C]);
            self::event($pdo, $cs . '_A', $id('VOLUNTEER_NOANSWER'), $t(25), 60, ['to_number' => self::VOLUNTEER_A]);
            self::event($pdo, $cs, $id('VOICEMAIL'), $t(30), 60, ['url' => 'https://api.twilio.com/Recordings/RE0' . $i]);
        }

        // 2026-06-12, Area 70 — modelled on a production trace: the call is blasted to A, B and C; all three
        // phones pick up (each leg is added to conference_participants at pick-up), only B accepts (event 12).
        // The participant clock runs exactly 4 hours behind the event clock, as on the production host.
        $p = static fn (string $hms): string => '2026-06-12 ' . sprintf('%02d', (int) substr($hms, 0, 2) - 4) . substr($hms, 2);
        self::record($pdo, 'CA_BLAST', '2026-06-12 15:00:00', '2026-06-12 15:01:50', 110);
        self::event($pdo, 'CA_BLAST', $id('VOLUNTEER_SEARCH'), '2026-06-12 15:00:01', 70);
        self::event($pdo, 'CA_BLAST', $id('CALLER_IN_CONFERENCE'), '2026-06-12 15:00:03', 70);
        self::participant($pdo, 'CA_BLAST', 'CF_BLAST', 1, $p('15:00:03'));
        foreach (['A' => self::VOLUNTEER_A, 'B' => self::VOLUNTEER_B, 'C' => self::VOLUNTEER_C] as $who => $num) {
            self::event($pdo, 'CA_BLAST', $id('VOLUNTEER_DIALED'), '2026-06-12 15:00:04', 70, ['to_number' => substr($num, 2)]);
        }
        foreach (['A' => [5, self::VOLUNTEER_A], 'B' => [7, self::VOLUNTEER_B], 'C' => [9, self::VOLUNTEER_C]] as $who => [$sec, $num]) {
            self::event($pdo, 'CA_BLAST_' . $who, $id('VOLUNTEER_ANSWERED'), sprintf('2026-06-12 15:00:%02d', $sec), 70, ['to_number' => $num]);
            self::participant($pdo, 'CA_BLAST_' . $who, 'CF_BLAST', 2, $p(sprintf('15:00:%02d', $sec)));
        }
        self::event($pdo, 'CA_BLAST_B', $id('VOLUNTEER_IN_CONFERENCE'), '2026-06-12 15:00:12', 70, ['to_number' => self::VOLUNTEER_B]);
        // Production: phones still connected when someone else accepts are logged as declined with digits null.
        self::event($pdo, 'CA_BLAST_A', $id('VOLUNTEER_REJECTED'), '2026-06-12 15:00:14', 70, ['digits' => null, 'to_number' => self::VOLUNTEER_A]);
        self::event($pdo, 'CA_BLAST_B', $id('VOLUNTEER_HUP'), '2026-06-12 15:01:40', 70);
        self::event($pdo, 'CA_BLAST', $id('CALLER_HUP'), '2026-06-12 15:01:40', 70);

        // 2026-06-13, Area 80 — production pattern since mid-September: YAP logs the dial (5) and the caller
        // hanging up (13) but nothing about the volunteer. One call has the volunteer line in the conference.
        self::record($pdo, 'CA_NL1', '2026-06-13 19:46:00', '2026-06-13 20:58:20', 4340);
        self::event($pdo, 'CA_NL1', $id('VOLUNTEER_SEARCH'), '2026-06-13 19:46:20', null);
        self::event($pdo, 'CA_NL1', $id('CALLER_IN_CONFERENCE'), '2026-06-13 19:46:24', 80);
        self::event($pdo, 'CA_NL1', $id('VOLUNTEER_DIALED'), '2026-06-13 19:46:24', 80, ['to_number' => '5555550171']);
        self::participant($pdo, 'CA_NL1', 'CF_NL1', 1, '2026-06-13 19:46:24');
        self::participant($pdo, 'CA_NL1_V', 'CF_NL1', 2, '2026-06-13 19:46:40');
        self::event($pdo, 'CA_NL1', $id('CALLER_HUP'), '2026-06-13 20:58:09', 80);
        self::record($pdo, 'CA_NL2', '2026-06-13 21:00:00', '2026-06-13 21:01:00', 60);
        self::event($pdo, 'CA_NL2', $id('VOLUNTEER_SEARCH'), '2026-06-13 21:00:05', null);
        self::event($pdo, 'CA_NL2', $id('CALLER_IN_CONFERENCE'), '2026-06-13 21:00:08', 80);
        self::event($pdo, 'CA_NL2', $id('VOLUNTEER_DIALED'), '2026-06-13 21:00:08', 80, ['to_number' => '5555550171']);
        self::participant($pdo, 'CA_NL2', 'CF_NL2', 1, '2026-06-13 21:00:08');
        self::event($pdo, 'CA_NL2', $id('CALLER_HUP'), '2026-06-13 21:00:58', 80);
        self::record($pdo, 'CA_NL3', '2026-06-13 22:00:00', '2026-06-13 22:01:00', 60);
        self::event($pdo, 'CA_NL3', $id('VOLUNTEER_SEARCH'), '2026-06-13 22:00:05', null);
        self::event($pdo, 'CA_NL3', $id('VOLUNTEER_DIALED'), '2026-06-13 22:00:08', 80, ['to_number' => '5555550171']);
        self::event($pdo, 'CA_NL3', $id('CALLER_HUP'), '2026-06-13 22:00:58', 80);

        self::record($pdo, 'CA_NEW', '2026-07-01 12:00:00', '2026-07-01 12:01:00', 60);
        self::event($pdo, 'CA_NEW', $id('JFT_LOOKUP'), '2026-07-01 12:00:30', 10);

        // Volunteer names live in YAP's config table.
        $st = $pdo->prepare('INSERT INTO config (service_body_id, data, data_type) VALUES (?,?,?)');
        $st->execute([10, json_encode([['volunteer_name' => 'Alex Example', 'volunteer_phone_number' => self::VOLUNTEER_A, 'volunteer_enabled' => true, 'volunteer_shift_schedule' => base64_encode('[]')], ['volunteer_name' => 'Blair Example', 'volunteer_phone_number' => self::VOLUNTEER_B]]), '_YAP_VOLUNTEERS_V2_']);
        // Group volunteers live in their own row; the number is stored as a JSON number here on purpose.
        $pdo->prepare('INSERT INTO config (service_body_id, data, data_type, parent_id) VALUES (?,?,?,?)')
            ->execute([60, json_encode([['volunteer_name' => 'Casey Example', 'volunteer_phone_number' => 5555550173, 'volunteer_enabled' => true]]), '_YAP_GROUP_VOLUNTEERS_V2_', 7]);
        // A deleted config row (Status::DELETED = 1) must be ignored.
        $pdo->prepare('INSERT INTO config (service_body_id, data, data_type, status) VALUES (?,?,?,1)')
            ->execute([99, json_encode([['volunteer_name' => 'Deleted Example', 'volunteer_phone_number' => self::VOLUNTEER_A]]), '_YAP_VOLUNTEERS_V2_']);
    }

    public static function app(array $configOverrides = []): array
    {
        $dir = self::tempDir();
        $config = self::config($dir, $configOverrides);
        $pdo = self::pdo();
        $catalog = self::catalog();
        self::seed($pdo, $catalog);
        $app = new App($config, new Connection(null, $config, $pdo), $catalog);
        $app->clients()->initialise();
        return [$app, $dir];
    }

    /** @param array<string, string> $query */
    public static function request(string $path, array $query = [], ?string $token = null, bool $secure = true): \YapReporting\Http\Request
    {
        $server = ['REMOTE_ADDR' => '203.0.113.5', 'REQUEST_METHOD' => 'GET'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        return new \YapReporting\Http\Request('GET', $path, $query, $server, $secure, '203.0.113.5', 'test');
    }
}
