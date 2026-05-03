<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The first event for a request — emitted by `App::serve()` before
 * the middleware pipeline runs. Pairs with `ResponseEmitted`
 * (same `$pairId`).
 *
 * A listener building a span tree opens the root span here and
 * uses the `traceparent` header (set by the TraceContext
 * middleware on inbound, or generated locally) as the parent. The
 * pair id distinguishes concurrent requests within a single
 * worker — sync PHP serves one request per worker at a time, but
 * Swoole / RoadRunner deployments can interleave.
 */
final class RequestReceived implements FrameworkEvent
{
    public function __construct(
        public readonly string $pairId,
        public readonly ServerRequestInterface $request,
    ) {}
}
