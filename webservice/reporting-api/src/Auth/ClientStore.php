<?php

declare(strict_types=1);

namespace YapReporting\Auth;

/**
 * Persists API clients in data/clients.json. Only an HMAC-SHA256 of each secret is stored,
 * keyed with a server-side pepper in data/secret.key, so a leaked clients.json alone
 * cannot be used to authenticate.
 *
 * Token format: yapr_<12 hex client id>_<43 char base64url secret>  (256 bits of entropy)
 */
final class ClientStore
{
    public const TOKEN_PATTERN = '/^yapr_([0-9a-f]{12})_([A-Za-z0-9_\-]{43})$/';

    public function __construct(private readonly string $dataDir)
    {
    }

    public function clientsFile(): string
    {
        return $this->dataDir . '/clients.json';
    }

    public function keyFile(): string
    {
        return $this->dataDir . '/secret.key';
    }

    public function isInitialised(): bool
    {
        return is_file($this->keyFile()) && is_file($this->clientsFile());
    }

    /** Create the data directory, server pepper and empty client registry. Idempotent. */
    public function initialise(): void
    {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0700, true) && !is_dir($this->dataDir)) {
            throw new \RuntimeException('Cannot create data directory.');
        }
        @chmod($this->dataDir, 0700);
        if (!is_file($this->keyFile())) {
            $this->writeAtomic($this->keyFile(), base64_encode(random_bytes(32)));
        }
        if (!is_file($this->clientsFile())) {
            $this->writeAtomic($this->clientsFile(), (string) json_encode(['version' => 1, 'clients' => []], JSON_PRETTY_PRINT));
        }
    }

    public function pepper(): string
    {
        $raw = @file_get_contents($this->keyFile());
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if (!is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException('Server secret key missing. Run: php bin/yapr init');
        }
        return $key;
    }

    public function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->pepper());
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $raw = @file_get_contents($this->clientsFile());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['clients']) || !is_array($data['clients'])) {
            return [];
        }
        return $data['clients'];
    }

    /** @return array<string, mixed>|null */
    public function record(string $id): ?array
    {
        $all = $this->all();
        return isset($all[$id]) && is_array($all[$id]) ? $all[$id] : null;
    }

    /**
     * @param int[] $serviceBodyIds
     * @param string[] $scopes
     * @param string[] $ipAllowlist
     * @return array{0: string, 1: string} [client id, plaintext token shown once]
     */
    public function create(string $name, bool $all, array $serviceBodyIds, bool $allowUnassigned, array $scopes, array $ipAllowlist): array
    {
        $id = bin2hex(random_bytes(6));
        $secret = self::newSecret();
        $this->mutate(function (array $clients) use ($id, $name, $all, $serviceBodyIds, $allowUnassigned, $scopes, $ipAllowlist, $secret): array {
            $clients[$id] = [
                'name' => $name,
                'created_at' => gmdate('c'),
                'revoked_at' => null,
                'all_service_bodies' => $all,
                'service_body_ids' => array_values(array_unique(array_map('intval', $serviceBodyIds))),
                'allow_unassigned' => $allowUnassigned,
                'scopes' => array_values(array_unique($scopes)),
                'ip_allowlist' => array_values($ipAllowlist),
                'token_hash' => $this->hashSecret($secret),
                'previous_token_hash' => null,
                'previous_token_expires_at' => null,
            ];
            return $clients;
        });
        return [$id, self::formatToken($id, $secret)];
    }

    /** Issue a new token. The previous token keeps working for $graceSeconds (0 = immediately invalid). */
    public function rotate(string $id, int $graceSeconds): string
    {
        $secret = self::newSecret();
        $this->mutate(function (array $clients) use ($id, $secret, $graceSeconds): array {
            if (!isset($clients[$id])) {
                throw new \InvalidArgumentException('Unknown client.');
            }
            $clients[$id]['previous_token_hash'] = $graceSeconds > 0 ? $clients[$id]['token_hash'] : null;
            $clients[$id]['previous_token_expires_at'] = $graceSeconds > 0 ? time() + $graceSeconds : null;
            $clients[$id]['token_hash'] = $this->hashSecret($secret);
            $clients[$id]['rotated_at'] = gmdate('c');
            return $clients;
        });
        return self::formatToken($id, $secret);
    }

    public function revoke(string $id): void
    {
        $this->mutate(function (array $clients) use ($id): array {
            if (!isset($clients[$id])) {
                throw new \InvalidArgumentException('Unknown client.');
            }
            $clients[$id]['revoked_at'] = gmdate('c');
            $clients[$id]['previous_token_hash'] = null;
            $clients[$id]['previous_token_expires_at'] = null;
            return $clients;
        });
    }

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void
    {
        $allowed = ['name', 'all_service_bodies', 'service_body_ids', 'allow_unassigned', 'scopes', 'ip_allowlist'];
        $this->mutate(function (array $clients) use ($id, $changes, $allowed): array {
            if (!isset($clients[$id])) {
                throw new \InvalidArgumentException('Unknown client.');
            }
            foreach ($changes as $k => $v) {
                if (in_array($k, $allowed, true)) {
                    $clients[$id][$k] = $v;
                }
            }
            return $clients;
        });
    }

    public static function toClient(string $id, array $r): Client
    {
        $scopes = array_values(array_intersect((array) ($r['scopes'] ?? []), Client::ALL_SCOPES));
        return new Client(
            $id,
            (string) ($r['name'] ?? $id),
            (bool) ($r['all_service_bodies'] ?? false),
            array_values(array_map('intval', (array) ($r['service_body_ids'] ?? []))),
            (bool) ($r['allow_unassigned'] ?? false),
            $scopes,
            array_values(array_map('strval', (array) ($r['ip_allowlist'] ?? []))),
        );
    }

    public static function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function formatToken(string $id, string $secret): string
    {
        return 'yapr_' . $id . '_' . $secret;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $fn */
    private function mutate(callable $fn): void
    {
        $lock = fopen($this->clientsFile() . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock client registry.');
        }
        try {
            flock($lock, LOCK_EX);
            $clients = $fn($this->all());
            $this->writeAtomic($this->clientsFile(), (string) json_encode(['version' => 1, 'clients' => $clients], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeAtomic(string $file, string $contents): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write ' . basename($file));
            }
            chmod($tmp, 0600);
            if (!rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Cannot replace ' . basename($file));
            }
        } finally {
            umask($old);
        }
    }
}
