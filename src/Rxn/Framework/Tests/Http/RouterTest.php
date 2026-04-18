<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;
use Rxn\Framework\Http\Route;
use Rxn\Framework\Http\RouteGroup;
use Rxn\Framework\Http\Router;

final class RouterTest extends TestCase
{
    public function testStaticRouteMatches(): void
    {
        $r = new Router();
        $r->get('/products', 'list');

        $hit = $r->match('GET', '/products');
        $this->assertNotNull($hit);
        $this->assertSame('list', $hit['handler']);
        $this->assertSame([], $hit['params']);
        $this->assertSame('/products', $hit['pattern']);
        $this->assertNull($hit['name']);
        $this->assertSame([], $hit['middlewares']);
    }

    public function testSinglePlaceholderCapturesOneSegment(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'show');

        $hit = $r->match('GET', '/products/42');
        $this->assertNotNull($hit);
        $this->assertSame(['id' => '42'], $hit['params']);
    }

    public function testMultiplePlaceholders(): void
    {
        $r = new Router();
        $r->get('/users/{userId}/orders/{orderId}', 'userOrder');

        $hit = $r->match('GET', '/users/7/orders/abc');
        $this->assertNotNull($hit);
        $this->assertSame(['userId' => '7', 'orderId' => 'abc'], $hit['params']);
    }

    public function testMethodMismatchReturnsNull(): void
    {
        $r = new Router();
        $r->get('/products', 'list');

        $this->assertNull($r->match('POST', '/products'));
        $this->assertTrue($r->hasMethodMismatch('POST', '/products'));
        $this->assertFalse($r->hasMethodMismatch('GET', '/products'));
    }

    public function testNoRouteReturnsNull(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'show');

        $this->assertNull($r->match('GET', '/unknown'));
        $this->assertFalse($r->hasMethodMismatch('GET', '/unknown'));
    }

    public function testPlaceholderDoesNotCrossSegmentBoundary(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'show');
        $this->assertNull($r->match('GET', '/products/42/reviews'));
    }

    public function testFirstRegisteredRouteWins(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'generic');
        $r->get('/products/new', 'newForm');

        $this->assertSame('generic', $r->match('GET', '/products/new')['handler']);
    }

    public function testQueryStringIsStripped(): void
    {
        $r = new Router();
        $r->get('/products', 'list');

        $this->assertSame('list', $r->match('GET', '/products?page=2&sort=name')['handler']);
    }

    public function testTrailingSlashTolerated(): void
    {
        $r = new Router();
        $r->get('/products', 'list');
        $this->assertNotNull($r->match('GET', '/products/'));
    }

    public function testRootRouteMatches(): void
    {
        $r = new Router();
        $r->get('/', 'home');
        $this->assertSame('home', $r->match('GET', '/')['handler']);
    }

    public function testAddAcceptsMultipleMethods(): void
    {
        $r = new Router();
        $r->add(['GET', 'HEAD'], '/healthz', 'health');

        $this->assertNotNull($r->match('GET', '/healthz'));
        $this->assertNotNull($r->match('HEAD', '/healthz'));
        $this->assertNull($r->match('POST', '/healthz'));
    }

    public function testAnyAcceptsEveryMethod(): void
    {
        $r = new Router();
        $r->any('/webhook', 'webhook');

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $m) {
            $this->assertNotNull($r->match($m, '/webhook'), "expected $m /webhook to match");
        }
    }

    public function testVerbHelpersRegisterCorrectMethod(): void
    {
        $r = new Router();
        $r->post('/products', 'create');
        $r->put('/products/{id}', 'replace');
        $r->patch('/products/{id}', 'update');
        $r->delete('/products/{id}', 'remove');
        $r->options('/products', 'options');

        $this->assertSame('create',  $r->match('POST',    '/products')['handler']);
        $this->assertSame('replace', $r->match('PUT',     '/products/1')['handler']);
        $this->assertSame('update',  $r->match('PATCH',   '/products/1')['handler']);
        $this->assertSame('remove',  $r->match('DELETE',  '/products/1')['handler']);
        $this->assertSame('options', $r->match('OPTIONS', '/products')['handler']);
    }

    public function testUnknownMethodRejected(): void
    {
        $r = new Router();
        $this->expectException(\InvalidArgumentException::class);
        $r->add('BREW', '/coffee', 'make');
    }

    public function testHandlerIsOpaque(): void
    {
        $r = new Router();
        $handler = fn () => 'hi';
        $r->get('/closure', $handler);

        $hit = $r->match('GET', '/closure');
        $this->assertSame($handler, $hit['handler']);
    }

    public function testDotAndDashInStaticSegmentsAreLiterals(): void
    {
        $r = new Router();
        $r->get('/v1.0/users-list', 'list');

        $this->assertNotNull($r->match('GET', '/v1.0/users-list'));
        $this->assertNull($r->match('GET', '/v1x0/users-list'));
    }

    // --- named routes ----------------------------------------------

    public function testNamedRouteUrlSubstitutesParams(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'show')->name('products.show');

        $this->assertSame('/products/42', $r->url('products.show', ['id' => 42]));
    }

    public function testUrlRejectsUnknownName(): void
    {
        $r = new Router();
        $r->get('/', 'home');
        $this->expectException(\InvalidArgumentException::class);
        $r->url('nope');
    }

    public function testUrlRejectsMissingPlaceholder(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'show')->name('products.show');

        $this->expectException(\InvalidArgumentException::class);
        $r->url('products.show');
    }

    public function testMatchReportsRouteName(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'show')->name('products.show');

        $this->assertSame('products.show', $r->match('GET', '/products/42')['name']);
    }

    // --- per-route middleware --------------------------------------

    public function testMatchReportsRouteMiddleware(): void
    {
        $r   = new Router();
        $mw1 = $this->passthrough();
        $mw2 = $this->passthrough();
        $r->get('/secret', 'handler')->middleware($mw1, $mw2);

        $hit = $r->match('GET', '/secret');
        $this->assertSame([$mw1, $mw2], $hit['middlewares']);
    }

    // --- groups ----------------------------------------------------

    public function testGroupPrependsPrefix(): void
    {
        $r = new Router();
        $r->group('/api/v1', function (RouteGroup $g) {
            $g->get('/products', 'list')->name('products.list');
        });

        $this->assertSame('list', $r->match('GET', '/api/v1/products')['handler']);
        $this->assertSame('/api/v1/products', $r->url('products.list'));
    }

    public function testGroupMiddlewareAttachesToEveryRoute(): void
    {
        $r    = new Router();
        $auth = $this->passthrough();
        $r->group('/api', function (RouteGroup $g) use ($auth) {
            $g->middleware($auth);
            $g->get('/me', 'me');
            $g->post('/logout', 'logout');
        });

        $this->assertSame([$auth], $r->match('GET',  '/api/me')['middlewares']);
        $this->assertSame([$auth], $r->match('POST', '/api/logout')['middlewares']);
    }

    public function testGroupsNest(): void
    {
        $r     = new Router();
        $outer = $this->passthrough();
        $inner = $this->passthrough();

        $r->group('/api', function (RouteGroup $g) use ($outer, $inner) {
            $g->middleware($outer);
            $g->group('/admin', function (RouteGroup $g) use ($inner) {
                $g->middleware($inner);
                $g->post('/users', 'create');
            });
            $g->get('/me', 'me');
        });

        $admin = $r->match('POST', '/api/admin/users');
        $this->assertNotNull($admin);
        $this->assertSame([$outer, $inner], $admin['middlewares']);

        $me = $r->match('GET', '/api/me');
        $this->assertSame([$outer], $me['middlewares']);
    }

    public function testRouteHandleIsReturnedByAdd(): void
    {
        $r     = new Router();
        $route = $r->get('/x', 'x');
        $this->assertInstanceOf(Route::class, $route);
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
