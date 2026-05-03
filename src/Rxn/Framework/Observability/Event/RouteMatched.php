<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

/**
 * Emitted by `Router::match()` when a route matches the incoming
 * request. The route template is the registered string from the
 * `#[Route]` attribute (e.g. `/users/{id:int}`); the resolved
 * params are the placeholder values extracted from the URL.
 *
 * Listeners typically tag the active span with the template
 * (low-cardinality, dashboard-friendly) and stash the params for
 * downstream events that don't carry them.
 *
 * Not emitted for unmatched routes — the absence of a
 * `RouteMatched` event between `RequestReceived` and the first
 * `MiddlewareEntered` is itself the signal.
 *
 * `$pairId` carries the request's pair id (the same id on the
 * surrounding `RequestReceived` / `ResponseEmitted`) when the
 * request flowed through `App::serve()`. Listeners use it to
 * attribute the route to the right in-flight request on
 * concurrent-worker setups (Swoole / RoadRunner). It's null
 * when callers drive the Router directly without going through
 * `App::serve()` — in that case there's no request scope to
 * attribute to.
 */
final class RouteMatched implements FrameworkEvent
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly string $method,
        public readonly string $template,
        public readonly array $params,
        public readonly ?string $pairId = null,
    ) {}
}
