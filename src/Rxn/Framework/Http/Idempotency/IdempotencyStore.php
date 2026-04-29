<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Idempotency;

/**
 * Storage contract for the Idempotency middleware. Four operations
 * cover the Stripe-style semantics:
 *
 *   - `lock`    — acquire an exclusive lock on `$key` for the time
 *                 the original request takes to process. Returns
 *                 false if a lock is already held; the middleware
 *                 then returns 409 Conflict.
 *   - `release` — drop the lock once processing finishes (success
 *                 or failure). Always called from a finally block.
 *   - `get`     — fetch a previously-stored response, or null if
 *                 no entry exists / has expired.
 *   - `put`     — record the response so retries can replay it,
 *                 with a TTL.
 *
 * Implementations of this interface need to be honest about
 * concurrency: `lock` MUST be atomic (compare-and-set) — a sloppy
 * "check then write" implementation lets two concurrent requests
 * both think they own the key. The shipped backends use atomic
 * file rename (`FileIdempotencyStore`) and PSR-16's structural
 * `has` + `set` (`Psr16IdempotencyStore`) respectively.
 */
interface IdempotencyStore
{
    /**
     * Acquire a lock on `$key`. Returns true if the caller now
     * owns the lock; false if the lock is already held by another
     * in-flight request. Implementations expire stale locks after
     * `$ttlSeconds` so a crashed process doesn't permanently
     * block the key.
     */
    public function lock(string $key, int $ttlSeconds): bool;

    /**
     * Release a previously-held lock. No-op if no lock is held.
     */
    public function release(string $key): void;

    /**
     * Fetch the stored response for `$key`, or null on miss.
     * Implementations honour the TTL set at `put` time.
     */
    public function get(string $key): ?StoredResponse;

    /**
     * Store `$response` against `$key` with `$ttlSeconds` until
     * expiry. Subsequent `get($key)` calls within the TTL return
     * the same `StoredResponse`.
     */
    public function put(string $key, StoredResponse $response, int $ttlSeconds): void;
}
