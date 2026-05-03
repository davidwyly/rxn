<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

/**
 * Emitted by `Binder::bind()` once per DTO bind. The class is the
 * DTO FQCN; the path is `compiled` if the binder dispatched to a
 * pre-compiled closure (post `warmFromProfile()` or `compileFor()`)
 * or `runtime` if it walked reflection.
 *
 * Listeners use the path tag to track compile-cache hit ratio —
 * the metric that tells operators whether their `dump:hot`
 * cadence is actually keeping up with hot-DTO drift.
 *
 * `$pairId` carries the request's pair id when the bind happened
 * inside an `App::serve()` request. It's null when `Binder::bind()`
 * is called outside a request scope (unit tests, scripts that
 * use the binder directly).
 */
final class BinderInvoked implements FrameworkEvent
{
    public const PATH_COMPILED = 'compiled';
    public const PATH_RUNTIME  = 'runtime';

    /** @param class-string $class */
    public function __construct(
        public readonly string $class,
        public readonly string $path,
        public readonly ?string $pairId = null,
    ) {}
}
