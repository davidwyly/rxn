<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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


    public function testNamedRouteUrlEncodesPathSegmentCharacters(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'show')->name('products.show');

        $this->assertSame('/products/a%2Fb', $r->url('products.show', ['id' => 'a/b']));
        $this->assertSame('/products/foo%3Fadmin%3Dtrue%23frag', $r->url('products.show', ['id' => 'foo?admin=true#frag']));
    }

    public function testNamedRouteUrlDoesNotProduceSchemeRelativeUrlFromRootPlaceholder(): void
    {
        $r = new Router();
        $r->get('/{target}', 'go')->name('go');

        $this->assertSame('/%2Fevil.example', $r->url('go', ['target' => '/evil.example']));
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

    public function testIntConstraintMatchesDigitsOnly(): void
    {
        $r = new Router();
        $r->get('/users/{id:int}', 'show');
        $this->assertNotNull($r->match('GET', '/users/42'));
        $this->assertNull($r->match('GET', '/users/abc'));
    }

    public function testSlugConstraintMatchesLowercaseAndDashes(): void
    {
        $r = new Router();
        $r->get('/posts/{slug:slug}', 'show');
        $this->assertNotNull($r->match('GET', '/posts/my-post-42'));
        $this->assertNull($r->match('GET', '/posts/My_Post'));
    }

    public function testUuidConstraintMatchesCanonicalForm(): void
    {
        $r = new Router();
        $r->get('/sessions/{id:uuid}', 'show');
        $this->assertNotNull($r->match('GET', '/sessions/550e8400-e29b-41d4-a716-446655440000'));
        $this->assertNull($r->match('GET', '/sessions/not-a-uuid'));
    }

    public function testCustomConstraintCanBeRegistered(): void
    {
        $r = (new Router())->constraint('year', '\d{4}');
        $r->get('/archive/{y:year}', 'show');
        $this->assertNotNull($r->match('GET', '/archive/1999'));
        $this->assertNull($r->match('GET', '/archive/42'));
    }

    public function testUnknownConstraintThrows(): void
    {
        $r = new Router();
        $this->expectException(\InvalidArgumentException::class);
        $r->get('/{id:nonsense}', 'x');
    }

    public function testUntypedPlaceholderStillMatchesAnySegment(): void
    {
        $r = new Router();
        $r->get('/anything/{x}', 'show');
        $this->assertNotNull($r->match('GET', '/anything/whatever-123'));
    }

    /**
     * Adversarial inputs for the route compiler. Each pattern is
     * something a fuzz test or hostile input could land on. We
     * assert that compile + match either succeeds (the pattern is
     * legitimately accepted) or throws a clean
     * InvalidArgumentException — never a PHP warning, never a
     * RuntimeException, never an "unintended match."
     */
    public static function adversarialPatterns(): iterable
    {
        // Format: [pattern, valid_url_to_match_or_null, invalid_url, throws_on_register]
        yield 'placeholder name has digit prefix'          => ['/{1bad:int}', null, null, true];
        yield 'placeholder name has hyphen'                => ['/{na-me:int}', null, null, true];
        yield 'placeholder type unknown'                   => ['/{x:never_heard_of_it}', null, null, true];
        yield 'literal regex chars in segment are quoted'  => ['/foo.bar/{id:int}', '/foo.bar/42', '/foo_bar/42', false];
        yield 'literal regex anchor in segment quoted'     => ['/^weird$/{id:int}', '/^weird$/7', '/zweirdz/7', false];
        yield 'two placeholders in adjacent segments'      => ['/u/{a:int}/p/{b:slug}', '/u/1/p/x-y', '/u/abc/p/x', false];
        yield 'uuid constraint rejects shortened uuid'     => ['/s/{id:uuid}', '/s/550e8400-e29b-41d4-a716-446655440000', '/s/550e8400-e29b-41d4-a716-44665544000', false];
        yield 'multi-segment value rejected by single-seg' => ['/u/{id:int}', '/u/42', '/u/42/extra', false];
        yield 'empty placeholder name'                     => ['/u/{:int}', null, null, true];
    }

    /** @dataProvider adversarialPatterns */
    public function testRouteCompilerHandlesAdversarialPatterns(
        string $pattern,
        ?string $shouldMatch,
        ?string $shouldNotMatch,
        bool $throwsOnRegister,
    ): void {
        $r = new Router();
        if ($throwsOnRegister) {
            $this->expectException(\InvalidArgumentException::class);
            $r->get($pattern, 'h');
            return;
        }
        $r->get($pattern, 'h');
        if ($shouldMatch !== null) {
            $this->assertNotNull(
                $r->match('GET', $shouldMatch),
                "expected '$pattern' to match '$shouldMatch'"
            );
        }
        if ($shouldNotMatch !== null) {
            $this->assertNull(
                $r->match('GET', $shouldNotMatch),
                "expected '$pattern' to NOT match '$shouldNotMatch'"
            );
        }
    }

    public function testTrailingSlashIsNormalised(): void
    {
        $r = new Router();
        $r->get('/products/{id:int}', 'h');
        $this->assertNotNull($r->match('GET', '/products/42'));
        $this->assertNotNull($r->match('GET', '/products/42/'));
    }

    public function testQueryStringIsStrippedBeforeMatch(): void
    {
        $r = new Router();
        $r->get('/products/{id:int}', 'h');
        // parse_url should drop ?foo=bar before regex matching.
        $this->assertNotNull($r->match('GET', '/products/42?foo=bar'));
    }

    public function testStaticHashmapDoesNotShadowEarlierPlaceholderRoute(): void
    {
        // Registration order: placeholder first, static second. The
        // placeholder must still win (it would have under the old
        // linear walk).
        $r = new Router();
        $r->get('/items/{id}', 'placeholder');
        $r->get('/items/special', 'static');

        $hit = $r->match('GET', '/items/special');
        $this->assertNotNull($hit);
        $this->assertSame('placeholder', $hit['handler']);
    }

    public function testStaticHashmapServesStaticWhenNoShadow(): void
    {
        $r = new Router();
        $r->get('/items/special', 'static');
        $r->get('/items/{id:int}', 'by-id');

        $a = $r->match('GET', '/items/special');
        $b = $r->match('GET', '/items/42');
        $this->assertSame('static', $a['handler']);
        $this->assertSame('by-id', $b['handler']);
    }

    private function passthrough(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request);
            }
        };
    }
}
