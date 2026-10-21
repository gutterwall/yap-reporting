<?php

declare(strict_types=1);

namespace YapReporting\Support;

/**
 * JSON-lines logger. It scrubs anything that resembles a phone number, bearer token,
 * Twilio SID or password before writing. Callers must still never pass PII or secrets.
 */
final class Logger
{
    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    public static function scrub(string $text): string
    {
        $patterns = [
            '/yapr_[A-Za-z0-9_\-]+/' => 'yapr_[redacted]',
            '/Bearer\s+\S+/i' => 'Bearer [redacted]',
            '/\b(CA|SM|MM|RE|CF|PN)[0-9a-fA-F]{32}\b/' => '$1[redacted]',
            '/\+?\d[\d\-\s()]{6,}\d/' => '[number-redacted]',
            '/(password|passwd|pwd)\s*[=:]\s*\S+/i' => '$1=[redacted]',
        ];
        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        $record = ['ts' => gmdate('c'), 'level' => $level, 'event' => $event];
        foreach ($context as $k => $v) {
            $record[$k] = is_scalar($v) || $v === null ? (is_string($v) ? self::scrub($v) : $v) : self::scrub((string) json_encode($v));
        }
        $line = (string) json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
        $file = $this->dir . '/yapr-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('[yap-reporting] ' . $line);
        }
    }
}
