<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Resource\Fixture;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Identity-checkable middleware for tests. Carries a tag the
 * test reads back via `assertContains($mw, $middlewares)` to
 * verify which routes ended up with which middleware. Doesn't
 * touch the request — passes straight through to the handler.
 */
final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(public readonly string $tag) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return $handler->handle($request);
    }
}
