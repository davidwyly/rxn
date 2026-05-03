<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

use Psr\Http\Message\ResponseInterface;

/**
 * Last event for a request — emitted by `App::serve()` after the
 * pipeline returns and immediately before the response is written
 * to the wire. Pairs with `RequestReceived` (same `$pairId`).
 *
 * Only emitted on the success path. If the pipeline throws and
 * the exception propagates past `App::serve()`, no
 * `ResponseEmitted` fires — listeners infer "request died" from
 * the absence of an exit event for the same pair id. Apps that
 * want failed-request observability install an exception-handling
 * middleware in the pipeline; the middleware's own
 * `MiddlewareExited` event carries the throwable.
 */
final class ResponseEmitted implements FrameworkEvent
{
    public function __construct(
        public readonly string $pairId,
        public readonly ResponseInterface $response,
    ) {}
}
