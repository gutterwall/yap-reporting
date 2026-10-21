<?php

declare(strict_types=1);

namespace YapReporting\Security;

/** IPv4 / IPv6 address and CIDR matching. */
final class IpMatcher
{
    /** @param array<int, mixed> $rules */
    public static function matchesAny(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && self::matches($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        if (!str_contains($rule, '/')) {
            $ruleBin = @inet_pton($rule);
            return $ruleBin !== false && hash_equals($ruleBin, $ipBin);
        }
        [$subnet, $bits] = explode('/', $rule, 2);
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || !ctype_digit($bits) || strlen($subnetBin) !== strlen($ipBin)) {
            return false;
        }
        $bits = (int) $bits;
        if ($bits > strlen($ipBin) * 8) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remaining)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    public static function isValidRule(string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return filter_var($rule, FILTER_VALIDATE_IP) !== false;
        }
        [$subnet, $bits] = explode('/', $rule, 2);
        $bin = @inet_pton($subnet);
        return $bin !== false && ctype_digit($bits) && (int) $bits <= strlen($bin) * 8;
    }
}
