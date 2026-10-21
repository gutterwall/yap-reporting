<?php

declare(strict_types=1);

namespace YapReporting\Auth;

use YapReporting\Http\HttpException;
use YapReporting\Http\Request;
use YapReporting\Security\IpMatcher;
use YapReporting\Security\RateLimiter;
use YapReporting\Support\Logger;

/** Bearer-token authentication over HTTPS. */
final class TokenAuthenticator
{
    public function __construct(
        private readonly ClientStore $store,
        private readonly RateLimiter $limiter,
        private readonly Logger $logger,
        private readonly int $maxFailuresPer15Min,
    ) {
    }

    public function authenticate(Request $request): Client
    {
        $failKey = 'authfail:' . $request->clientIp;
        if ($this->limiter->exceeded($failKey, $this->maxFailuresPer15Min, 900)) {
            throw new HttpException(429, 'rate_limited', 'Too many failed authentication attempts. Try again later.', ['Retry-After' => '900']);
        }

        $header = $request->authorizationHeader();
        if ($header === null || preg_match('/^Bearer\s+(\S+)$/', trim($header), $m) !== 1) {
            $this->fail($request, $failKey, 'missing_or_malformed_header', null);
        }
        $token = $m[1];

        if (preg_match(ClientStore::TOKEN_PATTERN, $token, $parts) !== 1) {
            $this->fail($request, $failKey, 'malformed_token', null);
        }
        [, $clientId, $secret] = $parts;

        $record = $this->store->record($clientId);
        $presented = $this->store->hashSecret($secret);

        // Always perform a comparison so unknown ids and wrong secrets take similar time.
        $current = is_array($record) ? (string) ($record['token_hash'] ?? '') : str_repeat('0', 64);
        $ok = hash_equals($current, $presented);

        if (!$ok && is_array($record) && !empty($record['previous_token_hash'])) {
            $expires = (int) ($record['previous_token_expires_at'] ?? 0);
            if ($expires > time() && hash_equals((string) $record['previous_token_hash'], $presented)) {
                $ok = true;
            }
        }

        if (!$ok || !is_array($record)) {
            $this->fail($request, $failKey, 'invalid_token', $clientId);
        }
        if (!empty($record['revoked_at'])) {
            $this->fail($request, $failKey, 'revoked_token', $clientId);
        }

        $client = ClientStore::toClient($clientId, $record);

        if ($client->ipAllowlist !== [] && !IpMatcher::matchesAny($request->clientIp, $client->ipAllowlist)) {
            $this->logger->warning('auth_ip_rejected', ['client_id' => $clientId, 'ip' => $request->clientIp, 'request_id' => $request->requestId]);
            throw new HttpException(403, 'ip_not_allowed', 'This client is not permitted from this network address.');
        }

        return $client;
    }

    private function fail(Request $request, string $failKey, string $reason, ?string $clientId): never
    {
        $this->limiter->increment($failKey, 900);
        // Only the non-secret client id prefix is ever logged.
        $this->logger->warning('auth_failed', [
            'reason' => $reason,
            'client_id' => $clientId,
            'ip' => $request->clientIp,
            'request_id' => $request->requestId,
        ]);
        throw new HttpException(401, 'unauthorized', 'Authentication required.', ['WWW-Authenticate' => 'Bearer realm="yap-reporting"']);
    }
}
