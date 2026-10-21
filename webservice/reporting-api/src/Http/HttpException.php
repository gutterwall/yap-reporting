<?php

declare(strict_types=1);

namespace YapReporting\Http;

/** An error that maps directly to a JSON error response. Messages must never contain secrets or PII. */
final class HttpException extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
