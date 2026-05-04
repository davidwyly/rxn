<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute\Fixture;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stand-in for an auth-style middleware that short-circuits with
 * 401 instead of calling `$handler->handle()`. Used by the
 * Scanner's "Deprecation must be outermost" test to prove that
 * the auto-attached Deprecation middleware decorates the
 * short-circuit response — clients hitting a deprecated endpoint
 * with a missing token still learn it's deprecated.
 */
final class AlwaysReject implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return new Response(
            401,
            ['Content-Type' => 'application/problem+json'],
            '{"type":"about:blank","title":"Unauthorized","status":401}',
        );
    }
}
