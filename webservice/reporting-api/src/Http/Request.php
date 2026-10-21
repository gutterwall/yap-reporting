<?php

declare(strict_types=1);

namespace YapReporting\Http;

use YapReporting\Config;
use YapReporting\Security\IpMatcher;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $server,
        public readonly bool $secure,
        public readonly string $clientIp,
        public readonly string $requestId,
    ) {
    }

    public static function fromGlobals(Config $config): self
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $path = self::normalisePath((string) ($server['REQUEST_URI'] ?? '/'));

        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $trusted = (array) $config->get('trusted_proxies', []);
        $fromTrustedProxy = $remote !== '' && IpMatcher::matchesAny($remote, $trusted);

        $ip = $remote;
        if ($fromTrustedProxy && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            // Walk right-to-left, skipping our own proxies; the first untrusted hop is the client.
            $hops = array_reverse(array_map('trim', explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])));
            foreach ($hops as $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    break;
                }
                $ip = $hop;
                if (!IpMatcher::matchesAny($hop, $trusted)) {
                    break;
                }
            }
        }

        $secure = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off';
        if (!$secure && $fromTrustedProxy) {
            $secure = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }

        return new self($method, $path, $_GET, $server, $secure, $ip, bin2hex(random_bytes(8)));
    }

    /** Reduce any request URI to the part beginning at /api/ so the service works under any base path. */
    public static function normalisePath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        $path = rawurldecode($path);
        $pos = strpos($path, '/api/');
        if ($pos === false) {
            return '/';
        }
        $path = substr($path, $pos);
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        $trimmed = rtrim($path, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }

    public function authorizationHeader(): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($this->server[$key])) {
                return (string) $this->server[$key];
            }
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    return (string) $value;
                }
            }
        }
        return null;
    }

    public function isLoopback(): bool
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return $remote === '127.0.0.1' || $remote === '::1';
    }
}
