<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Emitted by `Pipeline::handle()` immediately before each
 * middleware's `process()` call. Pairs with `MiddlewareExited`
 * (same `$pairId`). One pair per middleware invocation.
 *
 * `$middlewareClass` is the FQCN of the middleware (anonymous
 * classes serialise as `class@anonymous{...}`); listeners use it
 * as the span name. `$index` is the middleware's position in the
 * configured stack, useful for ordering / dropdowns in dashboards.
 */
final class MiddlewareEntered implements FrameworkEvent
{
    public function __construct(
        public readonly string $pairId,
        public readonly string $middlewareClass,
        public readonly int $index,
        public readonly ServerRequestInterface $request,
    ) {}
}
