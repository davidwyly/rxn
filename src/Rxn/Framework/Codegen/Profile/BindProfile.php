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
     * Persist the in-memory counter to `$path` as JSON. Three
     * concurrency guarantees layered together:
     *
     *   1. **Atomic write** via temp-file + `rename(2)`: a reader
     *      either sees the old file or the new one, never a half-
     *      written one.
     *
     *   2. **Inter-process exclusion** via `flock(LOCK_EX)` on a
     *      sibling lock file: the read-merge-write sequence is
     *      serialised across workers, so two processes can't
     *      both read the same baseline, merge their separate
     *      hits, and have the later rename clobber the earlier
     *      one (which would lose the earlier worker's
     *      increments). The lock file is created on first flush
     *      and intentionally NOT unlinked — preserving it means
     *      subsequent flushes don't race on lock-file creation
     *      (which can itself lose to concurrent unlinks).
     *
     *   3. **Empty-state safety**: when neither an existing
     *      profile nor in-memory hits exist, the persisted file
     *      is `{}`, not `null`. A `null`-shaped file would crash
     *      the next `loadFrom()` call with a misleading error.
     */
    public static function flushTo(string $path): void
    {
        $lockPath = $path . '.lock';
        $lockFh = fopen($lockPath, 'c');
        if ($lockFh === false) {
            throw new \RuntimeException("BindProfile: cannot open lock $lockPath");
        }
        try {
            if (!flock($lockFh, LOCK_EX)) {
                throw new \RuntimeException("BindProfile: failed to acquire lock on $lockPath");
            }
            // Re-read AFTER acquiring the lock so we merge against
            // whatever the previous lock-holder wrote, not the
            // baseline we read before queueing.
            $merged = self::tryLoad($path) ?? [];
            foreach (self::$counts as $class => $count) {
                $merged[$class] = ($merged[$class] ?? 0) + $count;
            }
            $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $json = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new \RuntimeException('BindProfile: failed to encode counter');
            }
            if (file_put_contents($tmp, $json . "\n") === false) {
                @unlink($tmp);
                throw new \RuntimeException("BindProfile: failed to write $tmp");
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException("BindProfile: failed to rename $tmp -> $path");
            }
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    /**
     * Load `$path` into the in-memory counter, replacing any
     * existing data. Used by long-running workers on startup,
     * and by the `dump:hot` CLI to read what the request workers
     * have written.
     *
     * Distinguishes missing from corrupted to give the user a
     * useful error: "no profile at" means run a flushTo first;
     * "corrupted profile at" means the file is there but
     * unparseable (manual fix or delete).
     */
    public static function loadFrom(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException("BindProfile: no profile at $path");
        }
        $loaded = self::tryLoad($path);
        if ($loaded === null) {
            throw new \RuntimeException(
                "BindProfile: corrupted profile at $path (unparseable JSON or wrong shape)"
            );
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
