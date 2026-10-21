<?php

declare(strict_types=1);

namespace YapReporting\Http;

final class Response
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    public function encode(): string
    {
        return json_encode(
            $this->body,
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    public function send(bool $secure, bool $headOnly): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header_remove('X-Powered-By');
            $headers = [
                'Content-Type' => 'application/json; charset=utf-8',
                // Report data is sensitive: never store in shared or browser caches.
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ];
            if ($secure) {
                $headers['Strict-Transport-Security'] = 'max-age=31536000';
            }
            foreach (array_merge($headers, $this->headers) as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if (!$headOnly) {
            echo $this->encode();
        }
    }
}
