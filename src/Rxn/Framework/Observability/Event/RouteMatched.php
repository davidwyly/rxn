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
 */
final class RouteMatched implements FrameworkEvent
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly string $method,
        public readonly string $template,
        public readonly array $params,
    ) {}
}
