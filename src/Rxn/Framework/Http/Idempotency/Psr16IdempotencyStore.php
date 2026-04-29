<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Idempotency;

use Psr\SimpleCache\CacheInterface;

/**
 * Adapter from any PSR-16 cache to `IdempotencyStore`. Apps that
 * already run Redis / Memcached / APCu through `symfony/cache`,
 * `cache/redis-adapter`, or any other PSR-16 implementation drop
 * it in directly:
 *
 *   $mw = new IdempotencyMiddleware(
 *       new Psr16IdempotencyStore($psr16Cache),
 *       ...
 *   );
 *
 * # Why this works without `psr/simple-cache` in `require`
 *
 * The framework's `composer.json` lists `psr/simple-cache` only as
 * a `suggest`, not a `require`. Apps that don't use PSR-16 don't
 * pay for it. So how does this file declare a `CacheInterface`
 * type-hint when the package may not be installed?
 *
 * **PHP's lazy autoload of typed parameters.** The `use` statement
 * above is a namespace alias — PHP doesn't try to resolve it at
 * file-parse time. Resolution happens only when the symbol is
 * actually *referenced at runtime*: when someone calls
 * `new Psr16IdempotencyStore($cache)` and PHP needs to verify
 * `$cache instanceof CacheInterface`.
 *
 * So:
 *
 *  - The framework autoloads cleanly without `psr/simple-cache`
 *    installed — this file sits inert, never referenced, never
 *    autoloaded.
 *  - Anyone calling `new Psr16IdempotencyStore(...)` is passing a
 *    PSR-16 cache, which means they have `psr/simple-cache`
 *    installed. The autoload then resolves cleanly.
 *  - Reviewers see a normal nominal type-hint, no `object` +
 *    `method_exists` cleverness, no docblock gymnastics.
 *
 * Same trilemma busted as before — zero required deps, full
 * interop, no duplication — different load-bearing PHP feature.
 *
 * # Concurrency note
 *
 * The lock implementation uses the cache's `has + set + ttl` to
 * coordinate. This is **not atomic** at the cache level — a real
 * `setIfAbsent` semantic would be better — but PSR-16 doesn't
 * expose one. For high-contention deployments, implement
 * `IdempotencyStore` directly against the Redis client and use
 * `SET key value NX EX ttl` for an atomic acquire.
 */
final class Psr16IdempotencyStore implements IdempotencyStore
{
    private const LOCK_SUFFIX = ':lock';

    public function __construct(private readonly CacheInterface $cache)
    {
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
