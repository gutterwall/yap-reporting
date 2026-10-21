<?php

declare(strict_types=1);

namespace YapReporting\Privacy;

use YapReporting\Database\Connection;
use YapReporting\Yap\SchemaInspector;

/**
 * Resolves volunteer display names from YAP's `config` table (volunteer and group
 * volunteer lists are stored there as JSON). Only names are returned, and only to clients
 * holding the volunteer_names scope. Phone numbers are used as an in-memory join key only.
 */
final class VolunteerDirectory
{
    /** @var array<string, array{name: string, service_body_ids: int[]}>|null */
    private ?array $byNumber = null;

    public function __construct(private readonly Connection $db, private readonly SchemaInspector $schema)
    {
    }

    public function available(): bool
    {
        return $this->schema->has('config', 'data') && $this->schema->has('config', 'data_type');
    }

    public function nameFor(string $normalisedNumber): ?string
    {
        return $this->load()[$normalisedNumber]['name'] ?? null;
    }

    /** @return array<string, array{name: string, service_body_ids: int[]}> */
    public function load(): array
    {
        if ($this->byNumber !== null) {
            return $this->byNumber;
        }
        $this->byNumber = [];
        if (!$this->available()) {
            return $this->byNumber;
        }
        $sbCol = $this->schema->has('config', 'service_body_id') ? 'service_body_id' : 'NULL';
        // YAP DataType constants: _YAP_VOLUNTEERS_V2_ and _YAP_GROUP_VOLUNTEERS_V2_. Status::DELETED = 1.
        $statusFilter = $this->schema->has('config', 'status') ? ' AND (status IS NULL OR status <> 1)' : '';
        $rows = $this->db->select(
            'SELECT ' . $sbCol . ' AS sb, data FROM config WHERE data_type IN (?, ?)' . $statusFilter,
            ['_YAP_VOLUNTEERS_V2_', '_YAP_GROUP_VOLUNTEERS_V2_']
        );
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true); // tolerate double-encoded JSON
            }
            if (!is_array($decoded)) {
                continue;
            }
            $this->collect($decoded, $row['sb'] === null ? null : (int) $row['sb']);
        }
        return $this->byNumber;
    }

    private const PHONE_KEYS = ['volunteer_phone_number', 'phone_number', 'volunteer_number'];
    private const NAME_KEYS = ['volunteer_name', 'name', 'volunteer_full_name'];

    /** @param array<mixed> $node */
    private function collect(array $node, ?int $sb): void
    {
        $phone = null;
        foreach (self::PHONE_KEYS as $k) {
            if (isset($node[$k]) && is_scalar($node[$k])) {
                $phone = (string) $node[$k];
                break;
            }
        }
        if ($phone !== null) {
            $name = '';
            foreach (self::NAME_KEYS as $k) {
                if (isset($node[$k]) && is_scalar($node[$k]) && trim((string) $node[$k]) !== '') {
                    $name = trim((string) $node[$k]);
                    break;
                }
            }
            $num = Pseudonymizer::normaliseNumber($phone);
            if ($num !== null && $name !== '') {
                $entry = $this->byNumber[$num] ?? ['name' => $name, 'service_body_ids' => []];
                if ($sb !== null && !in_array($sb, $entry['service_body_ids'], true)) {
                    $entry['service_body_ids'][] = $sb;
                }
                $this->byNumber[$num] = $entry;
            }
            return;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collect($child, $sb);
            } elseif (is_string($child) && $child !== '' && ($child[0] === '[' || $child[0] === '{')) {
                // Nested JSON stored as a string inside the JSON.
                $decoded = json_decode($child, true);
                if (is_array($decoded)) {
                    $this->collect($decoded, $sb);
                }
            }
        }
    }

    /**
     * Structure-only description of the volunteer rows, for diagnosing missing names. Returns JSON key NAMES
     * and counts — never names, numbers or other values.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array
    {
        if (!$this->schema->has('config', 'data') || !$this->schema->has('config', 'data_type')) {
            return [];
        }
        $cols = ['data_type', 'data'];
        foreach (['service_body_id', 'parent_id', 'status'] as $c) {
            $cols[] = $this->schema->has('config', $c) ? $c : 'NULL AS ' . $c;
        }
        $out = [];
        foreach ($this->db->select('SELECT id, ' . implode(', ', $cols) . " FROM config WHERE data_type LIKE '%VOLUNTEER%' OR data_type LIKE '%GROUP%'") as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            $keys = [];
            $entries = 0;
            $named = 0;
            $walk = function ($node) use (&$walk, &$keys, &$entries, &$named): void {
                if (!is_array($node)) {
                    return;
                }
                $isEntry = false;
                foreach (array_keys($node) as $k) {
                    if (is_string($k)) {
                        $keys[$k] = true;
                        if (in_array($k, self::PHONE_KEYS, true)) {
                            $isEntry = true;
                        }
                    }
                }
                if ($isEntry) {
                    $entries++;
                    foreach (self::NAME_KEYS as $nk) {
                        if (isset($node[$nk]) && trim((string) $node[$nk]) !== '') {
                            $named++;
                            break;
                        }
                    }
                }
                foreach ($node as $child) {
                    $walk($child);
                }
            };
            $walk($decoded);
            $out[] = [
                'id' => (int) $row['id'],
                'data_type' => (string) $row['data_type'],
                'service_body_id' => $row['service_body_id'] === null ? null : (int) $row['service_body_id'],
                'parent_id' => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
                'status' => isset($row['status']) ? (int) $row['status'] : null,
                'valid_json' => is_array($decoded),
                'volunteer_entries' => $entries,
                'entries_with_name' => $named,
                'json_keys' => array_keys($keys),
            ];
        }
        return $out;
    }
}
