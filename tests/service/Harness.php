<?php

declare(strict_types=1);

use FakeYap\Installer;
use FakeYap\Scenario;
use YapReporting\App;
use YapReporting\Auth\Client;
use YapReporting\Config;
use YapReporting\Http\Request;

/**
 * The webservice running in-process against the fake YAP backend on a real MySQL / MariaDB server.
 *
 * Environment: YAPR_TEST_DB_HOST (127.0.0.1), YAPR_TEST_DB_PORT (3306), YAPR_TEST_DB_ADMIN_USER (root),
 * YAPR_TEST_DB_ADMIN_PASS (empty), YAPR_TEST_DB_NAME (yapr_service_test).
 */
final class Harness
{
    public const SERVICE = __DIR__ . '/../../webservice/reporting-api';
    public const TZ = 'America/New_York';

    public readonly string $host;
    public readonly int $port;
    public readonly string $db;
    public readonly Scenario $scenario;

    /** Database time zone the data was written through (what YAP's server default was). */
    public string $zone = 'SYSTEM';

    /** Offset between the database clock and UTC while the data was written (seconds, local minus UTC). */
    public int $dbOffset = 0;

    /** @var string[] temporary directories to remove */
    private array $tmp = [];

    public function __construct()
    {
        $this->host = self::env('YAPR_TEST_DB_HOST', '127.0.0.1');
        $this->port = (int) self::env('YAPR_TEST_DB_PORT', '3306');
        $this->db = self::env('YAPR_TEST_DB_NAME', 'yapr_service_test');
        // Two months ending on a fixed day (no daylight-saving change inside, see README).
        $this->scenario = new Scenario('2026-09-30', 61, 7, self::TZ);
    }

    public static function env(string $key, string $default): string
    {
        $v = getenv($key);
        return is_string($v) && $v !== '' ? $v : $default;
    }

    public function admin(): PDO
    {
        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->host, $this->port),
            self::env('YAPR_TEST_DB_ADMIN_USER', 'root'), getenv('YAPR_TEST_DB_ADMIN_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** Fresh database whose server default time zone is $zone while YAP writes and the service reads. */
    public function install(string $zone): void
    {
        $admin = $this->admin();
        $admin->exec('SET GLOBAL time_zone = ' . $admin->quote($zone));
        $this->zone = $zone;
        Installer::database($admin, $this->db, 'yapr_t_yap', 'yap-pass', 'yapr_t_reader', 'reader-pass', true);
        $yap = $this->connect('yapr_t_yap', 'yap-pass');
        $this->dbOffset = (int) $yap->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
        Installer::seed($yap, $this->scenario);
    }

    public function connect(string $user, string $pass): PDO
    {
        return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->db), $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * The service as installed beside YAP, reading as the restricted account unless told otherwise.
     *
     * @param array<string, mixed> $config
     */
    public function app(array $config = [], bool $fullAccount = false): App
    {
        $data = $this->tempDir();
        $config = array_replace_recursive([
            'yap_root' => __DIR__ . '/../../fake-yap/yap-root',
            'data_dir' => $data,
            'cache_ttl_seconds' => 0,
            'database' => [
                'host' => $this->host, 'port' => $this->port, 'database' => $this->db,
                'username' => $fullAccount ? 'yapr_t_yap' : 'yapr_t_reader',
                'password' => $fullAccount ? 'yap-pass' : 'reader-pass',
            ],
        ], $config);
        $app = new App(new Config($config, self::SERVICE));
        $app->clients()->initialise();
        return $app;
    }

    /**
     * @param int[]|null $areas null = every service body
     * @param string[]|null $scopes
     */
    public function token(App $app, ?array $areas = null, ?array $scopes = null, bool $unassigned = true): string
    {
        [, $token] = $app->clients()->create('test', $areas === null, $areas ?? [], $unassigned, $scopes ?? Client::ALL_SCOPES, []);
        return $token;
    }

    /** @param array<string, string> $query */
    public function call(App $app, string $token, string $path, array $query = []): \YapReporting\Http\Response
    {
        $server = ['REMOTE_ADDR' => '198.51.100.7', 'REQUEST_METHOD' => 'GET', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        return $app->handle(new Request('GET', $path, $query, $server, true, '198.51.100.7', bin2hex(random_bytes(4))));
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed> data
     */
    public function get(App $app, string $token, string $path, array $query = []): array
    {
        $r = $this->call($app, $token, $path, $query);
        if ($r->status !== 200) {
            throw new RuntimeException($path . ' answered ' . $r->status . ': ' . json_encode($r->body));
        }
        return $r->body['data'];
    }

    /** @return array{0: int, 1: int} [from, to) for local dates, end inclusive */
    public function window(string $fromDate, string $toDate): array
    {
        $tz = new DateTimeZone(self::TZ);
        return [(new DateTimeImmutable($fromDate, $tz))->getTimestamp(), (new DateTimeImmutable($toDate, $tz))->modify('+1 day')->getTimestamp()];
    }

    /** @return array<string, string> */
    public static function range(string $from, string $to): array
    {
        return ['start_date' => $from, 'end_date' => $to, 'timezone' => self::TZ];
    }

    public function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/yapr-service-' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        $this->tmp[] = $dir;
        return $dir;
    }

    public function cleanup(): void
    {
        foreach ($this->tmp as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->tmp = [];
    }

    /**
     * Compare every predicted figure with the service's metrics.
     *
     * @param array<string, mixed> $expected
     * @param array<string, array<string, mixed>> $metrics
     */
    public static function sameFigures(array $expected, array $metrics, string $label): void
    {
        $diff = [];
        foreach ($expected as $key => $value) {
            $got = isset($metrics[$key]) && array_key_exists('value', $metrics[$key]) ? $metrics[$key]['value'] : '(missing)';
            if (($metrics[$key]['available'] ?? false) !== true) {
                $got = '(unavailable)';
            }
            if ($got !== $value) {
                $diff[] = $key . ': expected ' . json_encode($value) . ', got ' . json_encode($got);
            }
        }
        if ($diff !== []) {
            throw new RuntimeException($label . ":\n          " . implode("\n          ", $diff));
        }
    }
}
