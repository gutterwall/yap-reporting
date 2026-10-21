<?php

declare(strict_types=1);

namespace YapReporting\Yap;

use YapReporting\Database\Connection;

/**
 * Detects which YAP tables / columns exist so reporting adapts to the installed schema
 * (pre-Laravel installs, 4.x, 5.x) instead of assuming it.
 */
final class SchemaInspector
{
    /** Columns the reporting engine can use, per table. Anything else (e.g. from_number) is never selected. */
    public const KNOWN = [
        'records_events' => ['id', 'callsid', 'event_id', 'event_time', 'service_body_id', 'meta', 'type'],
        'records' => ['id', 'callsid', 'start_time', 'end_time', 'duration', 'to_number', 'type', 'payload'],
        'conference_participants' => ['id', 'callsid', 'conferencesid', 'role', 'timestamp', 'created_at', 'friendlyname'],
        'config' => ['id', 'service_body_id', 'data', 'data_type', 'parent_id', 'status'],
        // YAP's own map of child call legs to the parent (caller) call, with the call's service body.
        'cache_records_conference_participants' => ['parent_callsid', 'callsid', 'service_body_id'],
    ];
    // Never read: users (password hashes), sessions (PINs), alerts, cache, metrics, event_status.

    /** @var array<string, string[]>|null */
    private ?array $columns = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, string[]> table => present known columns */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $present = [];
        if ($this->db->driver() === 'sqlite') {
            foreach (array_keys(self::KNOWN) as $table) {
                $cols = [];
                foreach ($this->db->select('PRAGMA table_info(' . $table . ')') as $row) {
                    $cols[] = strtolower((string) $row['name']);
                }
                if ($cols !== []) {
                    $present[$table] = array_values(array_intersect(self::KNOWN[$table], $cols));
                }
            }
        } else {
            $tables = array_keys(self::KNOWN);
            $placeholders = implode(',', array_fill(0, count($tables), '?'));
            $rows = $this->db->select(
                'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
                $tables
            );
            $all = [];
            foreach ($rows as $row) {
                $all[strtolower((string) $row['t'])][] = strtolower((string) $row['c']);
            }
            foreach ($all as $table => $cols) {
                $present[$table] = array_values(array_intersect(self::KNOWN[$table], $cols));
            }
        }
        return $this->columns = $present;
    }

    public function hasTable(string $table): bool
    {
        return isset($this->columns()[$table]);
    }

    public function has(string $table, string $column): bool
    {
        return in_array($column, $this->columns()[$table] ?? [], true);
    }

    /** First existing column among candidates. */
    public function firstOf(string $table, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if ($this->has($table, $c)) {
                return $c;
            }
        }
        return null;
    }

    /** records_events must have these for any reporting to work. */
    public function coreUsable(): bool
    {
        foreach (['callsid', 'event_id', 'event_time'] as $c) {
            if (!$this->has('records_events', $c)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $out = [];
        foreach (self::KNOWN as $table => $_) {
            $out[$table] = $this->hasTable($table) ? ['present' => true, 'columns' => $this->columns()[$table]] : ['present' => false];
        }
        return $out;
    }
}
