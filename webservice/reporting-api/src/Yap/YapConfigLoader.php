<?php

declare(strict_types=1);

namespace YapReporting\Yap;

use YapReporting\Config;

/**
 * Locates YAP's database credentials.
 *
 * YAP (4.x and 5.x) keeps them in config.php as:
 *     static $mysql_hostname = "";
 *     static $mysql_username = "";
 *     static $mysql_password = "";
 *     static $mysql_database = "";
 *
 * config.php is PARSED with PHP's tokenizer, never executed, so nothing in it can run
 * inside this service. Only literal string / integer values are accepted. If an operator
 * uses expressions (e.g. getenv()), set 'database' in reporting-config.php instead.
 *
 * Priority: reporting-config.php 'database' override  >  YAP config.php  >  YAP Laravel .env
 */
final class YapConfigLoader
{
    private const CONFIG_KEYS = ['mysql_hostname', 'mysql_username', 'mysql_password', 'mysql_database', 'mysql_port'];

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string, socket: ?string, source: string} */
    public function databaseCredentials(): array
    {
        $override = $this->config->get('database');
        if (is_array($override) && !empty($override['database'])) {
            return $this->normalise($override, 'reporting-config.php');
        }

        $file = $this->locateConfigPhp();
        if ($file !== null) {
            $vars = self::parseStaticAssignments((string) file_get_contents($file), self::CONFIG_KEYS);
            if (!empty($vars['mysql_database'])) {
                return $this->normalise([
                    'host' => $vars['mysql_hostname'] ?? 'localhost',
                    'port' => $vars['mysql_port'] ?? null,
                    'database' => $vars['mysql_database'],
                    'username' => $vars['mysql_username'] ?? '',
                    'password' => $vars['mysql_password'] ?? '',
                ], 'yap-config.php');
            }
        }

        $env = $this->locateEnv();
        if ($env !== null) {
            $vars = self::parseDotEnv((string) file_get_contents($env));
            if (!empty($vars['DB_DATABASE'])) {
                return $this->normalise([
                    'host' => $vars['DB_HOSTNAME'] ?? $vars['DB_HOST'] ?? 'localhost',
                    'port' => $vars['DB_PORT'] ?? null,
                    'database' => $vars['DB_DATABASE'],
                    'username' => $vars['DB_USERNAME'] ?? '',
                    'password' => $vars['DB_PASSWORD'] ?? '',
                    'socket' => $vars['DB_SOCKET'] ?? null,
                ], 'yap-.env');
            }
        }

        throw new \RuntimeException('Could not locate YAP database settings. Set yap_root or database in reporting-config.php.');
    }

    public function locateConfigPhp(): ?string
    {
        $explicit = $this->config->get('yap_config_file');
        if (is_string($explicit) && $explicit !== '') {
            return is_readable($explicit) ? $explicit : null;
        }
        $root = $this->config->yapRoot();
        // Mirrors YAP's config/database.php: config.<ENVIRONMENT>.php when ENVIRONMENT is set, else config.php.
        $env = getenv('ENVIRONMENT');
        $name = is_string($env) && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $env) === 1 ? 'config.' . $env . '.php' : 'config.php';
        foreach ([$root . '/' . $name, $root . '/src/' . $name, dirname($root) . '/' . $name] as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public function locateEnv(): ?string
    {
        $explicit = $this->config->get('yap_env_file');
        if (is_string($explicit) && $explicit !== '') {
            return is_readable($explicit) ? $explicit : null;
        }
        $root = $this->config->yapRoot();
        foreach ([$root . '/.env', $root . '/src/.env'] as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Extract `$name = <literal>;` assignments using the tokenizer. Commented-out lines are ignored.
     *
     * @param string[] $wanted
     * @return array<string, string|int>
     */
    public static function parseStaticAssignments(string $php, array $wanted): array
    {
        $tokens = token_get_all($php);
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            $name = substr($t[1], 1);
            if (!in_array($name, $wanted, true)) {
                continue;
            }
            $j = self::skipWhitespace($tokens, $i + 1);
            if ($j >= $count || $tokens[$j] !== '=') {
                continue;
            }
            $j = self::skipWhitespace($tokens, $j + 1);
            if ($j >= $count || !is_array($tokens[$j])) {
                continue;
            }
            $value = $tokens[$j];
            $next = self::skipWhitespace($tokens, $j + 1);
            if ($next >= $count || $tokens[$next] !== ';') {
                continue; // Not a simple literal (expression, concatenation, function call…).
            }
            if ($value[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[$name] = self::decodeStringLiteral($value[1]);
            } elseif ($value[0] === T_LNUMBER) {
                $out[$name] = (int) $value[1];
            }
        }
        return $out;
    }

    /** @return array<string, string> */
    public static function parseDotEnv(string $contents): array
    {
        $out = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            if (str_starts_with($k, 'export ')) {
                $k = trim(substr($k, 7));
            }
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                $v = substr($v, 1, -1);
            } else {
                $hash = strpos($v, ' #');
                if ($hash !== false) {
                    $v = rtrim(substr($v, 0, $hash));
                }
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private static function decodeStringLiteral(string $literal): string
    {
        $quote = $literal[0];
        $inner = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
        }
        // Double-quoted: support standard escapes. Interpolation is not evaluated.
        return stripcslashes($inner);
    }

    /** @param array<int, mixed> $tokens */
    private static function skipWhitespace(array $tokens, int $i): int
    {
        $n = count($tokens);
        while ($i < $n && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
        }
        return $i;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{host: string, port: int, database: string, username: string, password: string, socket: ?string, source: string}
     */
    private function normalise(array $raw, string $source): array
    {
        $host = (string) ($raw['host'] ?? 'localhost');
        $port = isset($raw['port']) && is_numeric($raw['port']) ? (int) $raw['port'] : 3306;
        // YAP allows "host:port" in $mysql_hostname.
        if (preg_match('/^([^:\[\]]+):(\d+)$/', $host, $m) === 1) {
            $host = $m[1];
            $port = (int) $m[2];
        }
        $socket = isset($raw['socket']) && is_string($raw['socket']) && $raw['socket'] !== '' ? $raw['socket'] : null;
        return [
            'host' => $host,
            'port' => $port,
            'database' => (string) $raw['database'],
            'username' => (string) ($raw['username'] ?? ''),
            'password' => (string) ($raw['password'] ?? ''),
            'socket' => $socket,
            'source' => $source,
        ];
    }
}
