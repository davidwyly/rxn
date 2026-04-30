<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;
use Rxn\Framework\Http\Route;

/**
 * Direct unit tests for the Route handle. Router-level integration
 * is covered by RouterTest; this exercises the handle's mutating
 * setters in isolation so a refactor of Route doesn't silently
 * change them out from under Router.
 */
final class RouteTest extends TestCase
{
    private function buildRoute(): Route
    {
        return new Route(
            methods:    ['GET'],
            regex:      '#^/products/(\d+)$#',
            paramNames: ['id'],
            handler:    'show',
            pattern:    '/products/{id:int}',
        );
    }

    public function testReadonlyConstructorFieldsAreExposed(): void
    {
        $route = $this->buildRoute();

        $this->assertSame(['GET'], $route->methods);
        $this->assertSame('#^/products/(\d+)$#', $route->regex);
        $this->assertSame(['id'], $route->paramNames);
        $this->assertSame('show', $route->handler);
        $this->assertSame('/products/{id:int}', $route->pattern);
    }

    public function testNameSetterIsChainable(): void
    {
        $route = $this->buildRoute();

        $this->assertNull($route->getName());
        $this->assertSame($route, $route->name('products.show'));
        $this->assertSame('products.show', $route->getName());
    }

    public function testMiddlewareAccumulatesAcrossCalls(): void
    {
        $route = $this->buildRoute();
        $a     = $this->passthrough();
        $b     = $this->passthrough();
        $c     = $this->passthrough();

        $this->assertSame([], $route->getMiddlewares());
        $route->middleware($a, $b);
        $route->middleware($c);

        // Second call appends; it does not replace.
        $this->assertSame([$a, $b, $c], $route->getMiddlewares());
    }

    public function testMiddlewareIsChainable(): void
    {
        $route = $this->buildRoute();
        $this->assertSame($route, $route->middleware($this->passthrough()));
    }

    private function passthrough(): Middleware
    {
        return new class implements Middleware {
            public function handle(Request $request, callable $next): Response
            {
                return $next($request);
            }
        };
    }
}
