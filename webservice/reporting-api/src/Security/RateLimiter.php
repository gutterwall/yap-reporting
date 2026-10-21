<?php

declare(strict_types=1);

namespace YapReporting\Security;

use YapReporting\Http\HttpException;

/** Fixed-window rate limiter backed by small lock-protected files in the data directory. */
final class RateLimiter
{
    public function __construct(private readonly string $dir)
    {
    }

    /** Record a hit; throw 429 when the limit for the window is exceeded. */
    public function hit(string $key, int $limit, int $windowSeconds): void
    {
        if ($limit <= 0) {
            return;
        }
        $count = $this->increment($key, $windowSeconds);
        if ($count > $limit) {
            throw new HttpException(429, 'rate_limited', 'Too many requests. Try again later.', [
                'Retry-After' => (string) $windowSeconds,
            ]);
        }
    }

    /** Check without incrementing. */
    public function exceeded(string $key, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0) {
            return false;
        }
        $state = $this->read($key);
        return $state !== null && time() - $state['start'] < $windowSeconds && $state['count'] >= $limit;
    }

    public function increment(string $key, int $windowSeconds): int
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return 0; // Fail open on storage problems rather than locking out the service.
        }
        $file = $this->path($key);
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return 0;
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $now = time();
            if (!is_array($state) || !isset($state['start'], $state['count']) || $now - (int) $state['start'] >= $windowSeconds) {
                $state = ['start' => $now, 'count' => 0];
            }
            $state['count'] = (int) $state['count'] + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($state));
            fflush($fh);
            return $state['count'];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @return array{start: int, count: int}|null */
    private function read(string $key): ?array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $state = json_decode((string) @file_get_contents($file), true);
        if (!is_array($state) || !isset($state['start'], $state['count'])) {
            return null;
        }
        return ['start' => (int) $state['start'], 'count' => (int) $state['count']];
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
