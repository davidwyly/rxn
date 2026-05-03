<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Idempotency\Event;

/**
 * Fired when the Idempotency middleware finds a stored response
 * for an incoming key + matching fingerprint and short-circuits
 * the pipeline with a replay. Useful for replay-attack detection
 * (a hit is a retry, but a flood of hits on different keys from
 * one client is suspicious) and replay-rate dashboards.
 *
 * Read-only by design: listeners observe, they don't influence
 * the response. Mutating the response is the middleware's job.
 */
final class IdempotencyHit
{
    public function __construct(
        public readonly string $key,
        public readonly int    $replayedStatus,
        public readonly string $fingerprint,
    ) {}
}
