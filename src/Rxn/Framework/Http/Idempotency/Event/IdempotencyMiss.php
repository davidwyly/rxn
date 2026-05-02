<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Idempotency\Event;

/**
 * Fired when the Idempotency middleware enters the cold path —
 * the key was not in the store, so the inner handler is about to
 * run. Useful for surfacing how often the middleware is genuinely
 * preventing duplicate work vs how often it just adds storage
 * overhead (a miss-heavy distribution suggests clients aren't
 * actually retrying, in which case the feature is dead weight).
 */
final class IdempotencyMiss
{
    public function __construct(
        public readonly string $key,
        public readonly string $fingerprint,
    ) {}
}
