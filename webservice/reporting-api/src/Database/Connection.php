<?php

declare(strict_types=1);

namespace YapReporting\Database;

use PDO;
use YapReporting\Config;

/**
 * Read-only database access.
 *
 * Even though YAP's own (read/write) credentials are used, writes are blocked three ways:
 *  1. Only statements beginning with SELECT / SHOW / PRAGMA are accepted by select().
 *  2. The MySQL session is put into READ ONLY transaction mode on connect, so the server
 *     itself rejects INSERT/UPDATE/DELETE/DDL on this connection.
 *  3. All parameters are bound; no SQL is ever built from request input.
 */
final class Connection
{
    private ?PDO $pdo;

    /** @param array<string, mixed>|null $credentials */
    public function __construct(private readonly ?array $credentials, private readonly Config $config, ?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): \Generator
    {
        if (preg_match('/^\s*(SELECT|SHOW|PRAGMA)\b/i', $sql) !== 1 || str_contains(rtrim(trim($sql), ';'), ';')) {
            throw new \LogicException('Only single read-only statements are permitted.');
        }
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $stmt->execute();
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
        $stmt->closeCursor();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function selectAll(string $sql, array $params = []): array
    {
        return iterator_to_array($this->select($sql, $params), false);
    }

    private function connect(): PDO
    {
        if ($this->credentials === null) {
            throw new \RuntimeException('No database credentials available.');
        }
        $c = $this->credentials;
        $dsn = $c['socket']
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['database'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['database']);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => $this->config->int('limits.connect_timeout_seconds', 5),
            PDO::ATTR_PERSISTENT => false,
        ];
        $tls = $this->config->get('database_tls');
        // YAP reads the same CA path from the MYSQL_ATTR_SSL_CA environment variable.
        if (!is_array($tls) && is_string(getenv('MYSQL_ATTR_SSL_CA')) && getenv('MYSQL_ATTR_SSL_CA') !== '') {
            $tls = ['ca' => getenv('MYSQL_ATTR_SSL_CA'), 'verify_server_cert' => true];
        }
        if (is_array($tls) && !empty($tls['ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = (string) $tls['ca'];
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) ($tls['verify_server_cert'] ?? true);
        }

        try {
            $pdo = new PDO($dsn, (string) $c['username'], (string) $c['password'], $options);
        } catch (\PDOException $e) {
            // Never surface the DSN or credentials.
            throw new \RuntimeException('Database connection failed (SQLSTATE ' . $e->getCode() . ').');
        }

        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        // YAP's date columns are TIMESTAMP, which MySQL/MariaDB convert to and from the SESSION time zone.
        // YAP writes UTC strings through the server's default zone and never changes it, so this service must
        // read through the same default zone to get back exactly what YAP wrote. (Verified in production: forcing
        // '+00:00' shifted every event by the server's offset.) Override only if YAP's connection uses another zone.
        $tz = $this->config->get('database_time_zone');
        if (is_string($tz) && preg_match('#^([+-]\d{2}:\d{2}|[A-Za-z_]+(/[A-Za-z_\-]+)*|SYSTEM)$#', $tz) === 1) {
            $pdo->exec('SET time_zone = ' . $pdo->quote($tz));
        }
        $timeout = $this->config->int('limits.query_timeout_seconds', 20);
        // MySQL 5.7.8+ (milliseconds, SELECT only) — ignored on MariaDB.
        try {
            $pdo->exec('SET SESSION max_execution_time = ' . ($timeout * 1000));
        } catch (\PDOException) {
        }
        // MariaDB 10.1+ (seconds) — ignored on MySQL.
        try {
            $pdo->exec('SET SESSION max_statement_time = ' . $timeout);
        } catch (\PDOException) {
        }
        return $pdo;
    }
}
