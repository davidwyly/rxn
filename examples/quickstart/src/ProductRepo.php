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
    }

    /** @return array<int, array<string, mixed>> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function save(array $rows): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Atomic via temp + rename so a concurrent reader never
        // sees a half-written file.
        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->path);
    }
}
