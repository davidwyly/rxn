<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

use Psr\Http\Message\ResponseInterface;

/**
 * Last event for a request — emitted by `App::serve()` after the
 * response has been written to the wire. Pairs with
 * `RequestReceived` (same `$pairId`).
 *
 * Fires once per response that `App::serve()` writes:
 *   - Successful pipeline runs (the common case).
 *   - 404 / 405 misses (pipeline never built; psrProblem
 *     short-circuits — listeners still want to close the
 *     request span).
 *
 * Does NOT fire when an exception escapes the pipeline past
 * `App::serve()`. Listeners infer "request died" from the
 * absence of a `ResponseEmitted` carrying the same pair id.
 * Apps that want fully-symmetric observability install an
 * exception-handling middleware in the pipeline; that
 * middleware's own `MiddlewareExited` carries the throwable.
 *
 * Listeners that want to instrument BEFORE the wire write
 * (e.g. injecting a server-timing header) should hook into the
 * outermost middleware's `MiddlewareExited` instead — that event
 * fires after the response is built but before
 * `PsrAdapter::emit()` writes it.
 */
final class ResponseEmitted implements FrameworkEvent
{
    public function __construct(
        public readonly string $pairId,
        public readonly ResponseInterface $response,
    ) {}
}
