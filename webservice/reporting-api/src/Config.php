<?php

declare(strict_types=1);

namespace YapReporting;

/**
 * Service configuration. Values come from config/reporting-config.php (or the
 * file named by the YAPR_CONFIG environment variable) merged over secure defaults.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    private string $baseDir;

    /** @param array<string, mixed> $values */
    public function __construct(array $values, string $baseDir)
    {
        $this->values = self::merge(self::defaults(), $values);
        $this->baseDir = rtrim($baseDir, '/');
    }

    public static function load(string $baseDir): self
    {
        $file = getenv('YAPR_CONFIG') ?: $baseDir . '/config/reporting-config.php';
        $values = [];
        if (is_file($file)) {
            $loaded = require $file;
            if (!is_array($loaded)) {
                throw new \RuntimeException('reporting-config.php must return an array.');
            }
            $values = $loaded;
        }
        return new self($values, $baseDir);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            // Location of the YAP installation. Default: the directory that contains reporting-api/.
            'yap_root' => null,
            // Explicit path to YAP's config.php (auto-detected when null).
            'yap_config_file' => null,
            // Explicit path to YAP's Laravel .env (auto-detected when null; used only as a fallback).
            'yap_env_file' => null,
            // Explicit path to YAP's app/Constants/EventId.php (auto-detected when null).
            'event_ids_file' => null,
            // Manual event id overrides: ['SPAD_LOOKUP' => 23]. Only use after verifying your install.
            'event_overrides' => [],

            // Optional database override. When null, YAP's own credentials are read from config.php.
            // Shape: ['host' => '', 'port' => 3306, 'database' => '', 'username' => '', 'password' => '', 'socket' => null]
            'database' => null,
            // Optional TLS to MySQL: ['ca' => '/path/ca.pem', 'verify_server_cert' => true]
            'database_tls' => null,

            // Writable directory for clients.json, secret.key, rate-limit state, cache and logs.
            // MUST NOT be web-accessible. Default: reporting-api/data (protected by .htaccess).
            'data_dir' => null,

            // Session time zone for reading YAP's TIMESTAMP columns. null = server default (what YAP itself uses).
            'database_time_zone' => null,

            // Timezone of the date strings YAP writes into records/records_events. YAP writes UTC.
            'storage_timezone' => 'UTC',
            // Default reporting timezone when the client does not send one.
            'default_timezone' => 'America/New_York',
            'week_starts_on' => 'sunday',

            // How an answered call is decided:
            //   'accepted'    (default) — as in YAP: a volunteer answered, accepted (pressed 1) and was connected.
            //   'min_seconds' — additionally require the connection to last more than answered_min_seconds.
            'answered_rule' => 'accepted',
            'answered_min_seconds' => 5,

            'require_https' => true,
            // Allow plain HTTP only from loopback (local testing). Never enable on a public host.
            'allow_http_from_loopback' => false,
            // Reverse proxies whose X-Forwarded-For / X-Forwarded-Proto headers are trusted (IPs or CIDRs).
            'trusted_proxies' => [],

            'limits' => [
                'max_range_days' => 400,
                'max_per_page' => 200,
                'default_per_page' => 50,
                'max_areas' => 50,
                'max_event_rows' => 750000,
                'query_timeout_seconds' => 20,
                'connect_timeout_seconds' => 5,
            ],

            'rate_limit' => [
                'requests_per_minute' => 120,
                'auth_failures_per_15_minutes' => 20,
            ],

            // Use primary-key ranges to avoid full scans (YAP has no index on its time columns).
            'use_id_range' => true,

            // Response cache for computed reports (seconds). 0 disables.
            'cache_ttl_seconds' => 60,

            'attribution' => [
                // How a call is assigned to one service body:
                //   routing — service body of the last routing event (helpline route / volunteer dial / voicemail)
                //   first   — first event that carries a service body
                //   last    — last event that carries a service body
                'policy' => 'routing',
                // Optional helpline-number to BMLT service-body mapping, used when no event names a service body.
                // Keys are YOUR Twilio helpline numbers (never caller numbers). ['+15555550100' => 123]
                'line_mapping' => [],
            ],

            'yap_schema' => [
                // conference_participants.role values (YAP CallRole constants).
                'caller_role_value' => 1,
                'volunteer_role_value' => 2,
                // Keys inside records_events.meta JSON that may hold the dialed volunteer number.
                'volunteer_meta_keys' => ['to_number', 'volunteer_phone_number', 'phone_number', 'number'],
                // Read Twilio call Direction from records.payload (parsed in memory, never returned).
                'read_direction_from_payload' => true,
            ],

            // Installation-wide JFT / SPAD totals are returned alongside Area-attributed figures.
            'expose_installation_meditation_totals' => true,

            'debug' => false,
        ];
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public function int(string $path, int $default = 0): int
    {
        $v = $this->get($path, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    public function yapRoot(): string
    {
        $root = $this->get('yap_root');
        if (is_string($root) && $root !== '') {
            return rtrim($root, '/');
        }
        // Default: the folder that contains reporting-api. If YAP's files are not there (for example
        // reporting-api was uploaded into YAP's public/ folder), look up to three levels higher.
        $dir = dirname($this->baseDir);
        for ($i = 0; $i < 4 && $dir !== '/' && $dir !== '.'; $i++) {
            if (is_file($dir . '/app/Constants/EventId.php') || is_file($dir . '/config.php')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        return dirname($this->baseDir);
    }

    public function dataDir(): string
    {
        $dir = $this->get('data_dir');
        return is_string($dir) && $dir !== '' ? rtrim($dir, '/') : $this->baseDir . '/data';
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            // Lists (e.g. trusted_proxies, volunteer_meta_keys) replace rather than merge.
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
