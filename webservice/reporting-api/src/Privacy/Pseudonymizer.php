<?php

declare(strict_types=1);

namespace YapReporting\Privacy;

/**
 * Produces stable, non-reversible identifiers. Raw Twilio CallSids and phone numbers never
 * leave the service. The HMAC key is the server secret, so identifiers cannot be brute-forced
 * from a list of phone numbers by anyone who does not hold data/secret.key.
 */
final class Pseudonymizer
{
    public function __construct(private readonly string $key)
    {
    }

    /** Call id: c_<local yyyymmdd>_<20 hex>. The date lets /calls/{id} search a bounded window. */
    public function callId(string $rootCallSid, string $localYmd): string
    {
        return 'c_' . $localYmd . '_' . substr(hash_hmac('sha256', 'call|' . $rootCallSid, $this->key), 0, 20);
    }

    public function volunteerId(string $normalisedNumber): string
    {
        return 'v_' . substr(hash_hmac('sha256', 'volunteer|' . $normalisedNumber, $this->key), 0, 16);
    }

    public static function normaliseNumber(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1); // NANP: drop country code so +1555… and 555… match.
        }
        return strlen($digits) >= 7 ? $digits : null;
    }

    /** @return array{0: string, 1: string}|null [ymd, hash] */
    public static function parseCallId(string $id): ?array
    {
        if (preg_match('/^c_(\d{8})_([0-9a-f]{20})$/', $id, $m) !== 1) {
            return null;
        }
        return [$m[1], $m[2]];
    }
}
