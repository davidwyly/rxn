<?php declare(strict_types=1);

namespace Example;

/**
 * File-backed JSON store so the quickstart demonstrates real
 * persistence across requests on the built-in `php -S` server
 * (which reinitialises PHP state per request — instance state
 * doesn't survive the boundary).
 *
 * Real apps swap this for an `rxn-orm`-backed implementation or
 * whatever storage they prefer. The point of this class is to
 * keep the quickstart self-contained — no MySQL, no SQLite, no
 * external setup.
 *
 * Concurrency: `create()` takes an exclusive `flock` on a sibling
 * lock file across the whole load → modify → write cycle, so two
 * php-fpm workers can't clobber each other's increments. The
 * write itself is also atomic via temp + rename. Reads (`all()`,
 * `find()`) are unlocked — they may briefly observe an
 * almost-stale snapshot under contention but never a torn file.
 */
final class ProductRepo
{
    public function __construct(
        private readonly string $path = __DIR__ . '/../../../var/quickstart-products.json',
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->load());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->load()[$id] ?? null;
    }

    /** @return array<string, mixed> */
    public function create(CreateProduct $dto): array
    {
        $this->ensureDir();

        // Lock the whole read-modify-write cycle so concurrent
        // workers can't both compute id=N and produce two rows
        // with the same id.
        $lockPath = $this->path . '.lock';
        $lockFh   = fopen($lockPath, 'c');
        if ($lockFh === false) {
            throw new \RuntimeException("ProductRepo: cannot open lock $lockPath");
        }
        try {
            if (!flock($lockFh, LOCK_EX)) {
                throw new \RuntimeException("ProductRepo: failed to acquire lock on $lockPath");
            }
            $rows = $this->load();
            $id   = $rows === [] ? 1 : (max(array_keys($rows)) + 1);
            $row  = [
                'id'     => $id,
                'name'   => $dto->name,
                'price'  => $dto->price,
                'status' => $dto->status,
            ];
            $rows[$id] = $row;
            $this->save($rows);
            return $row;
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Corrupted store — treat as empty rather than crashing
            // the request. Real apps would surface this; the
            // quickstart prefers progress over alarms.
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("ProductRepo: cannot create dir $dir");
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function save(array $rows): void
    {
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Atomic via temp + rename so a concurrent reader never
        // sees a half-written file.
        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new \RuntimeException("ProductRepo: failed to write $tmp");
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("ProductRepo: failed to rename $tmp -> $this->path");
        }
    }
}
