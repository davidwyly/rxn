<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen;

/**
 * Process-wide on-disk dump cache for compiled PHP source. Used by
 * `Rxn\Framework\Container` (factory closures) and
 * `Rxn\Framework\Http\Binding\Binder` (DTO binding closures) to
 * write generated source to a file and `require` it back instead
 * of `eval`'ing it. opcache treats those files like any other PHP
 * source — preload-eligible, shared bytecode across workers,
 * shared JIT trace cache.
 *
 *   DumpCache::useDir(__DIR__ . '/var/cache/rxn');
 *
 * After that, components that go through `DumpCache::load()` write
 * `<sha1(source)>.php` and require it. Filenames are content
 * hashes, so a source change naturally produces a new file (old
 * files become orphans — clear the dir on deploy if you care).
 *
 * Without `useDir()`, `load()` returns null and components stay
 * on their existing eval paths. Zero behaviour change for apps
 * that don't opt in.
 *
 * The atomic-write logic uses a unique temp file plus `rename(2)`,
 * which POSIX guarantees as atomic within a single filesystem.
 * Concurrent writers race safely because filenames are content-
 * addressed: if two workers cold-start the same DTO simultaneously,
 * both temp files have identical content and either rename is
 * acceptable.
 */
final class DumpCache
{
    private static ?string $dir = null;

    /**
     * Configure the dump directory. Pass `null` to disable
     * (subsequent `load()` calls return null).
     */
    public static function useDir(?string $dir): void
    {
        if ($dir === null) {
            self::$dir = null;
            return;
        }
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("DumpCache: directory does not exist: $dir");
        }
        if (!is_writable($dir)) {
            throw new \InvalidArgumentException("DumpCache: directory is not writable: $dir");
        }
        self::$dir = rtrim($dir, '/');
    }

    public static function dir(): ?string
    {
        return self::$dir;
    }

    /**
     * Look up (or write) the cache file for `$source` and return
     * its `require` result. Returns `null` when no cache dir is
     * configured — the caller should fall back to `eval` in that
     * case.
     *
     * `$source` is the body that would be passed to `eval`, e.g.
     * `"return static fn (...) => new Foo(...);"` — without the
     * `<?php` open tag (DumpCache adds it).
     */
    public static function load(string $source): mixed
    {
        if (self::$dir === null) {
            return null;
        }
        $file = self::$dir . '/' . sha1($source) . '.php';
        if (!is_file($file)) {
            self::writeAtomic($file, "<?php\n" . $source . "\n");
        }
        return require $file;
    }

    /**
     * Delete every `*.php` in the dump dir. Useful for tests and
     * deploy hooks. Components that maintain their own in-memory
     * caches must clear those separately — `purgeFiles()` only
     * touches disk.
     */
    public static function purgeFiles(): void
    {
        if (self::$dir === null) {
            return;
        }
        foreach (glob(self::$dir . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Write `$content` to `$finalPath` via temp-file + atomic
     * rename. Safe under concurrent writers: filenames are content
     * hashes, so two workers writing the same file produce
     * identical content; whichever rename lands second is a no-op
     * from the reader's perspective.
     */
    private static function writeAtomic(string $finalPath, string $content): void
    {
        $tmp = $finalPath . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException("DumpCache: failed to write $tmp");
        }
        if (!@rename($tmp, $finalPath)) {
            @unlink($tmp);
            if (!is_file($finalPath)) {
                throw new \RuntimeException("DumpCache: failed to rename $tmp -> $finalPath");
            }
        }
    }
}
