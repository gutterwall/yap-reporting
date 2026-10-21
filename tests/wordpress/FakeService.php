<?php

declare(strict_types=1);

namespace YAPR\Tests;

use FakeYap\Installer;
use FakeYap\Scenario;
use YapReporting\App;
use YapReporting\Auth\Client;
use YapReporting\Config;
use YapReporting\Http\Request;

/**
 * Answers the plugin's outbound HTTP requests in-process, through pre_http_request:
 *
 *   https://yap.example.org/reporting-api/...   the real webservice code (YapReporting\App), reading the fake YAP
 *                                                database on the WordPress test database server
 *   https://bmlt.example.org/main_server/...     the fake BMLT root server
 *
 * Anything else is refused, so no test ever reaches the network.
 */
final class FakeService
{
    public const API = 'https://yap.example.org/reporting-api';
    public const BMLT = 'https://bmlt.example.org/main_server';

    public static App $app;
    public static Scenario $scenario;
    public static string $token = '';
    public static string $db = '';

    /** @var array<int, array{url: string, authorization: ?string}> */
    public static array $log = [];

    /** @var (callable(string, array): (array|\WP_Error|null))|null replaces the answer while set */
    public static $override = null;

    private static string $dataDir = '';

    public static function install(): void
    {
        [$host, $port] = self::dbHost();
        self::$db = getenv('YAPR_TEST_FAKE_DB') ?: 'yapr_wp_fake_yap';
        $admin = new \PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), getenv('YAPR_TEST_DB_ADMIN_USER') ?: 'root', getenv('YAPR_TEST_DB_ADMIN_PASS') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        Installer::database($admin, self::$db, 'yapr_wp_yap', 'yap-pass', 'yapr_wp_reader', 'reader-pass', true);
        $yap = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, self::$db), 'yapr_wp_yap', 'yap-pass', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $tz = new \DateTimeZone('America/New_York');
        // A hundred days up to ten minutes ago, so every period the plugin offers has data.
        self::$scenario = new Scenario((new \DateTimeImmutable('now', $tz))->format('Y-m-d'), 100, 11, 'America/New_York', time());
        Installer::seed($yap, self::$scenario);

        self::$dataDir = sys_get_temp_dir() . '/yapr-wp-service-' . bin2hex(random_bytes(4));
        self::$app = self::app(['database' => ['host' => $host, 'port' => $port, 'database' => self::$db, 'username' => 'yapr_wp_reader', 'password' => 'reader-pass']]);
        self::$token = self::credential(true, [], Client::ALL_SCOPES, true);
        add_filter('pre_http_request', [self::class, 'answer'], 10, 3);
        register_shutdown_function(static function () use ($admin): void {
            $admin->exec('DROP DATABASE IF EXISTS `' . self::$db . '`');
            exec('rm -rf ' . escapeshellarg(self::$dataDir));
        });
    }

    /** @param array<string, mixed> $config */
    private static function app(array $config): App
    {
        $app = new App(new Config($config + [
            'yap_root' => __DIR__ . '/../../fake-yap/yap-root',
            'data_dir' => self::$dataDir,
            'cache_ttl_seconds' => 0,
            // The suite sends far more requests, and deliberately bad credentials, than a site would.
            'rate_limit' => ['requests_per_minute' => 100000, 'auth_failures_per_15_minutes' => 100000],
        ], __DIR__ . '/../../webservice/reporting-api'));
        $app->clients()->initialise();
        return $app;
    }

    /**
     * A new credential, as `bin/yapr client:create` would issue it.
     *
     * @param int[] $areas
     * @param string[] $scopes
     */
    public static function credential(bool $all, array $areas, array $scopes, bool $unassigned): string
    {
        [, $token] = self::$app->clients()->create('WordPress test site', $all, $areas, $unassigned, $scopes, []);
        return $token;
    }

    /** @return array{0: string, 1: int} */
    private static function dbHost(): array
    {
        $parts = explode(':', DB_HOST);
        return [$parts[0], isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 3306];
    }

    /**
     * pre_http_request callback.
     *
     * @param false|array|\WP_Error $pre
     * @param array<string, mixed> $args
     * @return false|array|\WP_Error
     */
    public static function answer($pre, $args, $url)
    {
        $headers = (array) ($args['headers'] ?? []);
        self::$log[] = ['url' => (string) $url, 'authorization' => $headers['Authorization'] ?? null, 'args' => $args];
        if (self::$override !== null) {
            $answer = (self::$override)((string) $url, $args);
            if ($answer !== null) {
                return $answer;
            }
        }
        $parts = wp_parse_url((string) $url);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
        if (str_starts_with((string) $url, self::API . '/')) {
            $server = ['REMOTE_ADDR' => '203.0.113.20', 'REQUEST_METHOD' => (string) ($args['method'] ?? 'GET')];
            if (isset($headers['Authorization'])) {
                $server['HTTP_AUTHORIZATION'] = (string) $headers['Authorization'];
            }
            $request = new Request((string) ($args['method'] ?? 'GET'), Request::normalisePath((string) ($parts['path'] ?? '/')), $query, $server, true, '203.0.113.20', bin2hex(random_bytes(4)));
            $response = self::$app->handle($request);
            return self::response($response->status, $response->encode());
        }
        if ($origin . ($parts['path'] ?? '') === self::BMLT . '/client_interface/json/' && ($query['switcher'] ?? '') === 'GetServiceBodies') {
            return self::response(200, (string) json_encode(Scenario::bmltServiceBodies()));
        }
        return new \WP_Error('http_request_blocked', 'Tests may not reach ' . $url);
    }

    /** @return array<string, mixed> */
    public static function response(int $status, string $body, array $headers = ['content-type' => 'application/json; charset=utf-8']): array
    {
        return ['headers' => $headers, 'body' => $body, 'response' => ['code' => $status, 'message' => get_status_header_desc($status)], 'cookies' => [], 'filename' => null];
    }

    /** Requests made to the webservice since $since (an index into the log). */
    public static function apiRequests(int $since = 0): int
    {
        return count(array_filter(array_slice(self::$log, $since), static fn (array $r): bool => str_starts_with($r['url'], self::API)));
    }

    /**
     * Expected summary for local dates (inclusive).
     *
     * @param int[]|null $areas
     * @return array<string, mixed>
     */
    public static function expected(?array $areas, bool $unassigned, string $from, string $to): array
    {
        $tz = new \DateTimeZone('America/New_York');
        return self::$scenario->expected($areas, $unassigned, (new \DateTimeImmutable($from, $tz))->getTimestamp(), (new \DateTimeImmutable($to, $tz))->modify('+1 day')->getTimestamp());
    }
}
