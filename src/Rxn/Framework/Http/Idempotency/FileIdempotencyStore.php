<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Idempotency;

/**
 * File-backed idempotency store — the default backend. Zero
 * external dependencies, works on any filesystem with atomic
 * `rename`. Suitable for single-host apps; for multi-host setups
 * use `Psr16IdempotencyStore` against a shared cache (Redis,
 * Memcached) since the lock semantics depend on a single
 * filesystem.
 *
 * Storage layout: one file per key. Path:
 *
 *   <directory>/<sha256(key)>.json
 *
 * The hash buckets keys evenly across the filesystem and avoids
 * embedding user-supplied strings in filenames. Each file holds:
 *
 *   {"expires_at": <unix-ts>, "data": <StoredResponse::toArray()>}
 *
 * Locks are separate files (`<sha>.lock`). Atomic acquisition uses
 * `fopen($path, 'xb')` — fails fast if the lock already exists.
 * Stale locks (older than the TTL specified at `lock()` time) are
 * detected by checking mtime and removed automatically.
 */
final class FileIdempotencyStore implements IdempotencyStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException("FileIdempotencyStore: directory unavailable: $directory");
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException("FileIdempotencyStore: directory not writable: $directory");
        }
    }

    public function lock(string $key, int $ttlSeconds): bool
    {
        $lockPath = $this->lockPath($key);
        // Clear stale locks (older than $ttlSeconds) before trying
        // to acquire — handles the "previous process crashed" case.
        if (is_file($lockPath) && (time() - @filemtime($lockPath)) > $ttlSeconds) {
            @unlink($lockPath);
        }
        // Atomic create-or-fail. 'xb' returns false if the file
        // already exists — this is the lock-not-held check + acquire
        // in a single syscall, no race window.
        $handle = @fopen($lockPath, 'xb');
        if ($handle === false) {
            return false;
        }
        fwrite($handle, (string)getmypid());
        fclose($handle);
        return true;
    }

    public function release(string $key): void
    {
        @unlink($this->lockPath($key));
    }

    public function get(string $key): ?StoredResponse
    {
        $path = $this->dataPath($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $envelope = json_decode($raw, true);
        if (!is_array($envelope)
            || !isset($envelope['expires_at'], $envelope['data'])
            || !is_array($envelope['data'])
        ) {
            return null;
        }
        if ($envelope['expires_at'] < time()) {
            @unlink($path);
            return null;
        }
        return StoredResponse::fromArray($envelope['data']);
    }

    public function put(string $key, StoredResponse $response, int $ttlSeconds): void
    {
        $path = $this->dataPath($key);
        $envelope = [
            'expires_at' => time() + $ttlSeconds,
            'data'       => $response->toArray(),
        ];
        $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Atomic write: tmpfile + rename. Same pattern Filecache uses
        // — the rename is atomic on POSIX filesystems, so concurrent
        // readers either see the old file or the new one, never a
        // half-written one.
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new \RuntimeException("FileIdempotencyStore: failed to write $tmp");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("FileIdempotencyStore: failed to rename $tmp -> $path");
        }
    }

    private function dataPath(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    private function lockPath(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.lock';
    }
}
