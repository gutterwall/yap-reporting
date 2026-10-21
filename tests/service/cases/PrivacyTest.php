<?php

declare(strict_types=1);

use YapReporting\Auth\Client;
use YapReporting\Config;
use YapReporting\Database\Connection;

/*
 * Privacy and safety against a real database server. Every other suite already runs the service as an account that
 * MySQL lets read only the documented columns, so any query touching users, sessions, alerts or the caller's number
 * fails there; these cases cover the rest.
 */
return [
    'no response anywhere contains caller numbers, Twilio ids, recordings, locations or canaries' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app);
        $q = Harness::range('2026-09-01', '2026-09-30');
        $bodies = [];
        foreach (['capabilities', 'areas', 'summary', 'trends', 'distributions', 'meeting-lookups', 'meditations', 'sms-summary', 'volunteers', 'volunteer-summary'] as $endpoint) {
            $query = match ($endpoint) {
                'capabilities' => [],
                'summary', 'trends' => $q + ['compare' => 'true'],
                default => $q,
            };
            $bodies[] = json_encode($h->get($app, $token, '/api/v1/' . $endpoint, $query));
        }
        $bodies[] = json_encode($h->get($app, $token, '/api/v1/area-dashboard', $q + ['area_id' => '1001', 'compare' => 'true']));
        $calls = $h->get($app, $token, '/api/v1/calls', $q + ['per_page' => '200']);
        $bodies[] = json_encode($calls);
        foreach (array_slice($calls['calls'], 0, 60) as $call) {
            $bodies[] = json_encode($h->get($app, $token, '/api/v1/calls/' . $call['id'], ['timezone' => Harness::TZ]));
        }
        $all = implode("\n", $bodies);
        Assert::true(strlen($all) > 50000, 'responses were collected');
        foreach ([
            'caller number' => '/55501(5\d|[6-9]\d)/', 'helpline line number' => '/55501(0[0-5])/', 'Twilio call id' => '/\b(CA|SM|CF)[0-9a-f]{32}\b/',
            'recording URL' => '/Recordings|api\.twilio/', 'caller location' => '/Springfield|22801|latitude/', 'canary' => '/CANARY/i', 'full volunteer number' => '/5555550(11|12|13|14|15)\d/',
        ] as $what => $pattern) {
            Assert::true(preg_match($pattern, $all, $m) !== 1, $what . ' leaked: ' . ($m[0] ?? ''));
        }
    },

    'the restricted account cannot see what the service must never read' => function (Harness $h): void {
        $reader = $h->connect('yapr_t_reader', 'reader-pass');
        foreach (['SELECT name FROM users', 'SELECT pin FROM sessions', 'SELECT payload FROM alerts', 'SELECT from_number FROM records', 'SELECT friendlyname FROM conference_participants'] as $sql) {
            Assert::throws(static fn () => $reader->query($sql), PDOException::class);
        }
        Assert::same(1, (int) $reader->query("SELECT COUNT(*) > 0 FROM records_events")->fetchColumn(), 'but it can read the reporting tables');
    },

    'the service connection is read-only even with YAP\'s full account' => function (Harness $h): void {
        $app = $h->app([], true);
        $pdo = $app->db()->pdo();
        Assert::throws(static fn () => $pdo->exec("INSERT INTO records_events (callsid, event_id) VALUES ('x', 1)"), PDOException::class);
        Assert::throws(static fn () => $pdo->exec('DELETE FROM users'), PDOException::class);
        Assert::throws(static fn () => iterator_to_array($app->db()->select('UPDATE users SET name = 1')), LogicException::class);
        Assert::throws(static fn () => iterator_to_array($app->db()->select('SELECT 1; DROP TABLE users')), LogicException::class);
        Assert::same(1, (int) $h->connect('yapr_t_yap', 'yap-pass')->query("SELECT COUNT(*) FROM users WHERE name = 'CANARY-USER-NAME'")->fetchColumn(), 'nothing was changed');
        $token = $h->token($app);
        $h->get($app, $token, '/api/v1/summary', Harness::range('2026-09-01', '2026-09-30'));
    },

    'settings come from YAP\'s config.php, which is parsed and never executed' => function (Harness $h): void {
        $root = $h->tempDir();
        FakeYap\Installer::yapRoot($root, $h->host, $h->port, $h->db, 'yapr_t_reader', 'reader-pass');
        $marker = $root . '/executed';
        file_put_contents($root . '/config.php', "\nstatic \$google_maps_api_key = \"x\"; touch('$marker'); \$x = getenv('NOPE');\n", FILE_APPEND);
        $data = $h->tempDir();
        $app = new YapReporting\App(new Config(['yap_root' => $root, 'data_dir' => $data, 'cache_ttl_seconds' => 0], Harness::SERVICE));
        $app->clients()->initialise();
        $token = $h->token($app);
        $m = $h->get($app, $token, '/api/v1/summary', Harness::range('2026-09-01', '2026-09-30') + ['areas' => '1001'])['metrics'];
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        Assert::same($h->scenario->expected([1001], false, $a, $b)['total_calls'], $m['total_calls']['value']);
        Assert::true(!file_exists($marker), 'config.php was executed');
        $caps = $h->get($app, $token, '/api/v1/capabilities');
        Assert::same(28, count($caps['yap']['event_names']), 'events read from the installed YAP');
        Assert::true(str_ends_with($caps['yap']['event_catalog_source'], 'app/Constants/EventId.php'));
        foreach ($caps['metrics'] as $metric => $state) {
            Assert::true($state['available'], $metric . ' available with YAP 4.5.2: ' . ($state['reason'] ?? ''));
        }
    },

    'a wrong database password is reported without the password or host' => function (Harness $h): void {
        $app = $h->app(['database' => ['password' => 'definitely-wrong-password']]);
        $token = $h->token($app);
        $r = $h->call($app, $token, '/api/v1/summary', Harness::range('2026-09-01', '2026-09-30'));
        Assert::same(500, $r->status);
        Assert::notContains('definitely-wrong-password', json_encode($r->body) ?: '');
        Assert::notContains($h->host, json_encode($r->body) ?: '');
    },

    'Area-scoped credentials see only their Areas' => function (Harness $h): void {
        $app = $h->app();
        $token = $h->token($app, [1001], [Client::SCOPE_SUMMARY], false);
        $q = Harness::range('2026-09-01', '2026-09-30');
        Assert::same(403, $h->call($app, $token, '/api/v1/summary', $q + ['areas' => '1002'])->status);
        Assert::same(403, $h->call($app, $token, '/api/v1/summary', $q + ['areas' => '1001', 'include_unassigned' => 'true'])->status);
        Assert::same(403, $h->call($app, $token, '/api/v1/calls', $q)->status, 'summary scope only');
        Assert::same(403, $h->call($app, $token, '/api/v1/volunteer-summary', $q)->status);
        [$a, $b] = $h->window('2026-09-01', '2026-09-30');
        $m = $h->get($app, $token, '/api/v1/summary', $q)['metrics'];
        Assert::same($h->scenario->expected([1001], false, $a, $b)['total_calls'], $m['total_calls']['value'], 'defaults to its own Areas, no unassigned');
    },

    'call detail is limited to the credential\'s Areas and shows volunteers only with permission' => function (Harness $h): void {
        $app = $h->app();
        $admin = $h->token($app);
        $calls = $h->get($app, $admin, '/api/v1/calls', Harness::range('2026-09-01', '2026-09-30') + ['outcome' => 'answered', 'per_page' => '50'])['calls'];
        $other = null;
        $own = null;
        foreach ($calls as $c) {
            if ($c['service_body_id'] === 1002) {
                $other ??= $c['id'];
            } elseif ($c['service_body_id'] === 1001) {
                $own ??= $c['id'];
            }
        }
        Assert::true($own !== null && $other !== null);
        $scoped = $h->token($app, [1001], [Client::SCOPE_SUMMARY, Client::SCOPE_CALL_DETAILS], false);
        Assert::same(404, $h->call($app, $scoped, '/api/v1/calls/' . $other, ['timezone' => Harness::TZ])->status, 'same answer as a missing call');
        $detail = $h->get($app, $scoped, '/api/v1/calls/' . $own, ['timezone' => Harness::TZ])['call'];
        Assert::true(!isset($detail['answered_by']), 'no volunteer without volunteer_stats');
        $full = $h->get($app, $admin, '/api/v1/calls/' . $own, ['timezone' => Harness::TZ])['call'];
        Assert::true(isset($full['answered_by']['volunteer_id']));
    },
];
