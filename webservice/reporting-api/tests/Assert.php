<?php

declare(strict_types=1);

final class Assert
{
    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(($message !== '' ? $message . ' — ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true(bool $condition, string $message = 'condition is false'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function notContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new \RuntimeException(($message !== '' ? $message . ' — ' : '') . 'output unexpectedly contains "' . $needle . '"');
        }
    }

    /** @param callable(): mixed $fn */
    public static function throws(callable $fn, string $class, ?int $status = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new \RuntimeException('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
            }
            if ($status !== null && property_exists($e, 'status') && $e->status !== $status) {
                throw new \RuntimeException('expected status ' . $status . ', got ' . $e->status);
            }
            return;
        }
        throw new \RuntimeException('expected exception ' . $class . ' was not thrown');
    }
}
