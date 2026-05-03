<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

/**
 * Emitted around the user handler invocation in `App::serve()`.
 * `$pairId` brackets the `entered` and `exited` boundary the
 * same way as the middleware events; listeners pair them by id.
 *
 * `$state` is the lifecycle marker:
 *   - `entered` — handler is about to be called
 *   - `exited`  — handler returned normally (`$throwable` null)
 *                 or threw (`$throwable` set)
 *
 * `$handler` is a stringified handler descriptor (e.g.
 * `"Acme\\Controller\\UserController::show"` or `"Closure"`),
 * shaped for use as a span name. Apps with synthetic handlers
 * (anonymous closures or invokables) end up with `Closure` /
 * `__invoke` in the span tree — readable enough for the common
 * case, and the route template from `RouteMatched` carries the
 * disambiguating identity.
 */
final class HandlerInvoked implements FrameworkEvent
{
    public const STATE_ENTERED = 'entered';
    public const STATE_EXITED  = 'exited';

    public function __construct(
        public readonly string $pairId,
        public readonly string $state,
        public readonly string $handler,
        public readonly ?\Throwable $throwable = null,
    ) {}
}
