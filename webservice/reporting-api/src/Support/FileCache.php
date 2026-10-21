<?php

declare(strict_types=1);

namespace YapReporting\Support;

/** Short-lived cache for computed (already privacy-filtered) report payloads. Keys always include client scope. */
final class FileCache
{
    public function __construct(private readonly string $dir, private readonly int $ttl)
    {
    }

    /**
     * @param callable(): array<string, mixed> $producer
     * @return array<string, mixed>
     */
    public function remember(string $key, callable $producer): array
    {
        if ($this->ttl <= 0) {
            return $producer();
        }
        $file = $this->dir . '/' . hash('sha256', $key) . '.json';
        if (is_file($file) && time() - (int) filemtime($file) < $this->ttl) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        $value = $producer();
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($value), LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
        $this->gc();
        return $value;
    }

    public function clear(): int
    {
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    private function gc(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (time() - (int) @filemtime($f) > max(3600, $this->ttl * 10)) {
                @unlink($f);
            }
        }
    }
}
