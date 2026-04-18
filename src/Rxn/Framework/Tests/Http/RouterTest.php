<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
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

        // Two segments after /products should not match a single-placeholder route.
        $this->assertNull($r->match('GET', '/products/42/reviews'));
    }

    public function testFirstRegisteredRouteWins(): void
    {
        $r = new Router();
        $r->get('/products/{id}', 'generic');
        $r->get('/products/new', 'newForm');

        // Despite the more specific '/products/new' route, the first
        // registered pattern is consulted first. Order routes
        // specific-to-general when registering.
        $this->assertSame('generic', $r->match('GET', '/products/new')['handler']);
    }

    public function testQueryStringIsStripped(): void
    {
        $r = new Router();
        $r->get('/products', 'list');

        $hit = $r->match('GET', '/products?page=2&sort=name');
        $this->assertNotNull($hit);
        $this->assertSame('list', $hit['handler']);
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

        $this->assertSame('create', $r->match('POST', '/products')['handler']);
        $this->assertSame('replace', $r->match('PUT', '/products/1')['handler']);
        $this->assertSame('update', $r->match('PATCH', '/products/1')['handler']);
        $this->assertSame('remove', $r->match('DELETE', '/products/1')['handler']);
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

    public function testDotAndDashInStaticSegmentsAreNotRegexMetacharacters(): void
    {
        $r = new Router();
        $r->get('/v1.0/users-list', 'list');

        $this->assertNotNull($r->match('GET', '/v1.0/users-list'));
        // The '.' in the pattern must be a literal dot, not regex '.'.
        $this->assertNull($r->match('GET', '/v1x0/users-list'));
    }
}
