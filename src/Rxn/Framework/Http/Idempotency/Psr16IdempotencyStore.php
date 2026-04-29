<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Idempotency;

/**
 * Adapter from any PSR-16-shaped cache to `IdempotencyStore`,
 * **without importing PSR-16 nominally**. Apps that already run
 * Redis / Memcached / APCu through `symfony/cache`,
 * `cache/redis-adapter`, or any other PSR-16 implementation drop
 * it in directly:
 *
 *   $mw = new IdempotencyMiddleware(
 *       new Psr16IdempotencyStore($psr16Cache),
 *       ...
 *   );
 *
 * The constructor parameter is declared `object` (not
 * `\Psr\SimpleCache\CacheInterface`), and the four required methods
 * are validated structurally at construction. This means:
 *
 *  - **No new composer dependency.** Rxn's `composer.json` doesn't
 *    gain `psr/simple-cache`.
 *  - **Full PSR-16 interop.** Any object exposing
 *    `get(string)`, `set(string, mixed, int)`, `has(string)`, and
 *    `delete(string)` works.
 *  - **IDE-friendly** when PSR-16 is on the classpath. The
 *    docblock points at `\Psr\SimpleCache\CacheInterface`; IDEs
 *    that have the interface autoloaded surface the typed methods.
 *    IDEs that don't fall back to `object`.
 *
 * Concurrency: the lock implementation uses the cache's own
 * `has + set + ttl` to coordinate. This is **not atomic** at the
 * cache level — a redundant `setIfAbsent` semantic would be
 * better — but PSR-16 doesn't expose one. For high-contention
 * deployments, implement `IdempotencyStore` directly against the
 * Redis client and use `SET key value NX EX ttl` for an atomic
 * acquire.
 */
final class Psr16IdempotencyStore implements IdempotencyStore
{
    private const LOCK_SUFFIX = ':lock';

    /**
     * @param \Psr\SimpleCache\CacheInterface|object $cache
     *        Any PSR-16-shaped cache. Validated structurally at
     *        construction; no PSR-16 nominal type required.
     */
    public function __construct(private readonly object $cache)
    {
        foreach (['get', 'set', 'has', 'delete'] as $method) {
            if (!method_exists($cache, $method)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Psr16IdempotencyStore: cache object %s missing required method %s()',
                        $cache::class,
                        $method,
                    ),
                );
            }
        }
    }

    public function lock(string $key, int $ttlSeconds): bool
    {
        $lockKey = $key . self::LOCK_SUFFIX;
        // Best-effort atomicity within PSR-16's surface. A real
        // Redis-backed implementation should override this with
        // `SET ... NX EX` for true atomicity.
        if ($this->cache->has($lockKey)) {
            return false;
        }
        $this->cache->set($lockKey, 1, $ttlSeconds);
        return true;
    }

    public function release(string $key): void
    {
        $this->cache->delete($key . self::LOCK_SUFFIX);
    }

    public function get(string $key): ?StoredResponse
    {
        $raw = $this->cache->get($key);
        if (!is_array($raw)) {
            return null;
        }
        return StoredResponse::fromArray($raw);
    }

    public function put(string $key, StoredResponse $response, int $ttlSeconds): void
    {
        $this->cache->set($key, $response->toArray(), $ttlSeconds);
    }
}
