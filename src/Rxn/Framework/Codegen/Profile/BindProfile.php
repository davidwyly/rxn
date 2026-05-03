<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen\Profile;

/**
 * Process-wide hit counter for `Binder::bind()` calls. Drives
 * profile-guided compilation: at runtime we count which DTOs are
 * actually hot; at deploy / cron time, the `bin/rxn dump:hot`
 * subcommand reads this counter and compiles only the top-K, so
 * opcache memory doesn't pay for classes nobody hits.
 *
 * This is the runtime half — pure in-memory increment, persisted
 * to disk only when something explicitly asks. Per-bind cost is
 * one array key write (~50 ns); the file I/O happens on a
 * different cadence than the request.
 *
 * Persistence shape is a simple JSON object:
 *
 *   {
 *     "App\\Dto\\CreateProduct": 1843,
 *     "App\\Dto\\ListProducts":   12407,
 *     ...
 *   }
 *
 * Atomic via temp-file + rename so a crash mid-flush doesn't
 * leave a half-written profile that crashes the next reader.
 *
 * Sync-only — same posture as `RequestId` / `BearerAuth` /
 * `TraceContext`: PHP's single-threaded request lifecycle scopes
 * the static slot. Apps with sidecars (workers, Swoole) running
 * in shared memory should call `flushTo()` periodically (e.g.
 * every N requests, or on shutdown) and reset.
 */
final class BindProfile
{
    /** @var array<class-string, int> */
    private static array $counts = [];

    /**
     * Increment the hit counter for `$class`. Called from
     * `Binder::bind()` on the request-hot path; must be cheap.
     *
     * @param class-string $class
     */
    public static function record(string $class): void
    {
        self::$counts[$class] = (self::$counts[$class] ?? 0) + 1;
    }

    /**
     * Snapshot of the in-memory counter. Returned by reference?
     * No — callers shouldn't mutate the live counter.
     *
     * @return array<class-string, int>
     */
    public static function counts(): array
    {
        return self::$counts;
    }

    /**
     * Empty the in-memory counter. Used by tests, and by long-
     * running workers after a `flushTo()` so the next interval
     * starts fresh. Doesn't touch disk.
     */
    public static function reset(): void
    {
        self::$counts = [];
    }

    /**
     * Top-K classes by hit count, descending. Ties broken by
     * lexical class name so the result is deterministic for
     * snapshot tests. `$k <= 0` returns the empty list (caller
     * asked for nothing).
     *
     * @return list<class-string>
     */
    public static function topK(int $k): array
    {
        if ($k <= 0) {
            return [];
        }
        $counts = self::$counts;
        // Sort by (count desc, name asc) — stable + deterministic.
        $names = array_keys($counts);
        usort($names, static function (string $a, string $b) use ($counts): int {
            $byCount = $counts[$b] <=> $counts[$a];
            return $byCount !== 0 ? $byCount : strcmp($a, $b);
        });
        return array_slice($names, 0, $k);
    }

    /**
     * Persist the in-memory counter to `$path` as JSON. Atomic
     * via temp-file + `rename(2)` so a concurrent reader never
     * sees a half-written file.
     *
     * Merges with any existing counter at `$path` first, so
     * incremental flushes accumulate across processes (workers
     * and request-handlers each contribute hits without stomping
     * on each other).
     */
    public static function flushTo(string $path): void
    {
        $existing = self::tryLoad($path);
        $merged = $existing;
        foreach (self::$counts as $class => $count) {
            $merged[$class] = ($merged[$class] ?? 0) + $count;
        }
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException("BindProfile: failed to write $tmp");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("BindProfile: failed to rename $tmp -> $path");
        }
    }

    /**
     * Load `$path` into the in-memory counter, replacing any
     * existing data. Used by long-running workers on startup,
     * and by the `dump:hot` CLI to read what the request workers
     * have written.
     */
    public static function loadFrom(string $path): void
    {
        $loaded = self::tryLoad($path);
        if ($loaded === null) {
            throw new \RuntimeException("BindProfile: no profile at $path");
        }
        self::$counts = $loaded;
    }

    /**
     * @return array<class-string, int>|null
     */
    private static function tryLoad(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        // Defensive: drop entries with non-int values (corrupted
        // file or stale schema). Simple enough that we don't bail
        // on the whole file.
        $clean = [];
        foreach ($decoded as $class => $count) {
            if (is_string($class) && is_int($count) && $count >= 0) {
                $clean[$class] = $count;
            }
        }
        return $clean;
    }
}
