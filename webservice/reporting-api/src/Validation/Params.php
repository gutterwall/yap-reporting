<?php

declare(strict_types=1);

namespace YapReporting\Validation;

use YapReporting\Http\HttpException;

/** Strict query-parameter validation. Unknown parameters are rejected rather than ignored. */
final class Params
{
    /** @param array<string, mixed> $query */
    private function __construct(private readonly array $query)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param string[] $allowed
     */
    public static function from(array $query, array $allowed): self
    {
        foreach ($query as $key => $value) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new HttpException(400, 'unknown_parameter', 'Unsupported parameter: ' . self::safe((string) $key));
            }
            if (is_array($value)) {
                throw new HttpException(400, 'invalid_parameter', 'Parameter must be a single value: ' . self::safe((string) $key));
            }
            if (strlen((string) $value) > 512) {
                throw new HttpException(400, 'invalid_parameter', 'Parameter too long: ' . self::safe((string) $key));
            }
        }
        return new self($query);
    }

    /** @return array<string, mixed> Validated query, sorted (used for cache keys). */
    public function all(): array
    {
        $q = $this->query;
        ksort($q);
        return $q;
    }

    public function has(string $key): bool
    {
        return isset($this->query[$key]) && (string) $this->query[$key] !== '';
    }

    public function string(string $key): ?string
    {
        return $this->has($key) ? trim((string) $this->query[$key]) : null;
    }

    /** @param string[] $options */
    public function enum(string $key, array $options, ?string $default = null): ?string
    {
        $v = $this->string($key);
        if ($v === null) {
            return $default;
        }
        if (!in_array($v, $options, true)) {
            throw new HttpException(400, 'invalid_parameter', sprintf('%s must be one of: %s', $key, implode(', ', $options)));
        }
        return $v;
    }

    public function int(string $key, ?int $default, int $min, int $max): ?int
    {
        $v = $this->string($key);
        if ($v === null) {
            return $default;
        }
        if (preg_match('/^-?\d{1,10}$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
            throw new HttpException(400, 'invalid_parameter', sprintf('%s must be an integer between %d and %d.', $key, $min, $max));
        }
        return (int) $v;
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        $v = $this->string($key);
        if ($v === null) {
            return $default;
        }
        $v = strtolower($v);
        if (in_array($v, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no'], true)) {
            return false;
        }
        throw new HttpException(400, 'invalid_parameter', $key . ' must be true or false.');
    }

    /** Comma-separated positive integer ids. @return int[]|null */
    public function idList(string $key, int $maxCount): ?array
    {
        $v = $this->string($key);
        if ($v === null) {
            return null;
        }
        if (preg_match('/^\d{1,10}(,\d{1,10})*$/', $v) !== 1) {
            throw new HttpException(400, 'invalid_parameter', $key . ' must be a comma-separated list of numeric service body ids.');
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $v))));
        if (count($ids) > $maxCount) {
            throw new HttpException(400, 'too_many_areas', sprintf('At most %d service bodies may be requested at once.', $maxCount));
        }
        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new HttpException(400, 'invalid_parameter', $key . ' ids must be positive.');
            }
        }
        return $ids;
    }

    public function date(string $key): ?\DateTimeImmutable
    {
        $v = $this->string($key);
        if ($v === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, new \DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new HttpException(400, 'invalid_parameter', $key . ' must be a date in YYYY-MM-DD format.');
        }
        return $d;
    }

    public function timezone(string $key, string $default): \DateTimeZone
    {
        $v = $this->string($key) ?? $default;
        if (!in_array($v, \DateTimeZone::listIdentifiers(), true) && $v !== 'UTC') {
            throw new HttpException(400, 'invalid_parameter', $key . ' must be an IANA timezone such as America/New_York.');
        }
        return new \DateTimeZone($v);
    }

    public function pattern(string $key, string $regex, string $message): ?string
    {
        $v = $this->string($key);
        if ($v === null) {
            return null;
        }
        if (preg_match($regex, $v) !== 1) {
            throw new HttpException(400, 'invalid_parameter', $message);
        }
        return $v;
    }

    private static function safe(string $s): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '?', $s) ?? '', 0, 40);
    }
}
