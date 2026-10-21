<?php

declare(strict_types=1);

namespace YapReporting\Yap;

use YapReporting\Config;

/**
 * Maps YAP event names to the numeric ids stored in records_events.event_id.
 *
 * The ids are read from the INSTALLED copy of YAP (app/Constants/EventId.php) so the
 * reporting service always matches the YAP build it sits inside. No numeric ids are
 * hard-coded here. Metrics whose events are missing from the installed catalog are
 * reported as unavailable — never as zero.
 */
final class EventCatalog
{
    /** @var array<string, int> */
    private array $byName;

    /** @var array<int, string> */
    private array $byId;

    /** @param array<string, int> $map */
    public function __construct(array $map, public readonly string $source)
    {
        $this->byName = $map;
        $this->byId = [];
        foreach ($map as $name => $id) {
            $this->byId[$id] = $name;
        }
    }

    public static function fromConfig(Config $config): self
    {
        $map = [];
        $source = 'none';
        $file = self::locate($config);
        if ($file !== null) {
            $map = self::parse((string) file_get_contents($file));
            $source = 'yap:' . self::relativeTo($file, $config->yapRoot());
        }
        $overrides = $config->get('event_overrides', []);
        if (is_array($overrides) && $overrides !== []) {
            foreach ($overrides as $name => $id) {
                if (is_string($name) && preg_match('/^[A-Z][A-Z0-9_]*$/', $name) === 1 && is_numeric($id)) {
                    $map[$name] = (int) $id;
                }
            }
            $source .= '+overrides';
        }
        return new self($map, $source);
    }

    public static function locate(Config $config): ?string
    {
        $explicit = $config->get('event_ids_file');
        if (is_string($explicit) && $explicit !== '') {
            return is_readable($explicit) ? $explicit : null;
        }
        $root = $config->yapRoot();
        $candidates = [
            $root . '/app/Constants/EventId.php',
            $root . '/src/app/Constants/EventId.php',
            $root . '/lib/Constants/EventId.php',
        ];
        foreach ($candidates as $c) {
            if (is_readable($c)) {
                return $c;
            }
        }
        // Bounded search for unusual layouts.
        foreach ([$root . '/*/Constants/EventId.php', $root . '/*/*/Constants/EventId.php', $root . '/*/*/*/Constants/EventId.php'] as $pattern) {
            foreach (glob($pattern) ?: [] as $found) {
                if (is_readable($found) && !str_contains($found, '/vendor/') && !str_contains($found, '/reporting-api/')) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Parse `const NAME = 12;` (class constants) and `case NAME = 12;` (backed enums).
     *
     * @return array<string, int>
     */
    public static function parse(string $php): array
    {
        $map = [];
        if (preg_match_all('/\b(?:const|case)\s+([A-Z][A-Z0-9_]*)\s*=\s*(\d+)\s*;/', $php, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $row) {
                $map[$row[1]] = (int) $row[2];
            }
        }
        return $map;
    }

    public function id(string $name): ?int
    {
        return $this->byName[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    public function name(int $id): ?string
    {
        return $this->byId[$id] ?? null;
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return $this->byName;
    }

    public function isEmpty(): bool
    {
        return $this->byName === [];
    }

    /** SMS events are named *_SMS or SMS_* in YAP. */
    public static function isSmsName(?string $name): bool
    {
        return $name !== null && (str_ends_with($name, '_SMS') || str_starts_with($name, 'SMS_'));
    }

    private static function relativeTo(string $file, string $root): string
    {
        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : basename($file);
    }
}
