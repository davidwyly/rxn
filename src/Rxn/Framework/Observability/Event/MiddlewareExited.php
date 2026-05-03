<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

use Psr\Http\Message\ResponseInterface;

/**
 * Emitted by `Pipeline::handle()` immediately after each
 * middleware's `process()` call returns or throws. Pairs with
 * `MiddlewareEntered` (same `$pairId`). One pair per middleware
 * invocation.
 *
 * `$response` is null when the middleware threw — `$throwable`
 * carries the exception. Listeners close the open span and tag
 * it with the error if any. Both fields can also be null on
 * abnormal interpreter shutdown, but the framework itself doesn't
 * emit such events.
 */
final class MiddlewareExited implements FrameworkEvent
{
    public function __construct(
        public readonly string $pairId,
        public readonly string $middlewareClass,
        public readonly int $index,
        public readonly ?ResponseInterface $response,
        public readonly ?\Throwable $throwable = null,
    ) {}
}
