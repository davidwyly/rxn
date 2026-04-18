<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Container;
use Rxn\Framework\Http\Attribute\Scanner;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Tests\Http\Attribute\Fixture\OtherMiddleware;
use Rxn\Framework\Tests\Http\Attribute\Fixture\ProductsController;
use Rxn\Framework\Tests\Http\Attribute\Fixture\SampleMiddleware;

final class ScannerTest extends TestCase
{
    private function scan(): Router
    {
        $router = new Router();
        (new Scanner(new Container()))->register($router, [ProductsController::class]);
        return $router;
    }

    public function testRegistersRouteFromAttribute(): void
    {
        $router = $this->scan();
        $hit    = $router->match('GET', '/products/42');
        $this->assertNotNull($hit);
        $this->assertSame([ProductsController::class, 'show'], $hit['handler']);
        $this->assertSame(['id' => '42'], $hit['params']);
        $this->assertSame('products.show', $hit['name']);
    }

    public function testTypedConstraintRejectsMismatch(): void
    {
        $router = $this->scan();
        $this->assertNull($router->match('GET', '/products/not-an-int'));
    }

    public function testUrlReconstructsFromName(): void
    {
        $router = $this->scan();
        $this->assertSame('/products/7', $router->url('products.show', ['id' => 7]));
    }

    public function testClassLevelMiddlewareAppliesToEveryRoute(): void
    {
        $router = $this->scan();
        $hit    = $router->match('GET', '/products/1');
        $this->assertCount(1, $hit['middlewares']);
        $this->assertInstanceOf(SampleMiddleware::class, $hit['middlewares'][0]);
    }

    public function testMethodLevelMiddlewareStacksOntoClass(): void
    {
        $router = $this->scan();
        $hit    = $router->match('POST', '/products');
        $this->assertCount(2, $hit['middlewares']);
        $this->assertInstanceOf(SampleMiddleware::class, $hit['middlewares'][0]);
        $this->assertInstanceOf(OtherMiddleware::class, $hit['middlewares'][1]);
    }

    public function testRepeatedRouteAttributesEachRegister(): void
    {
        $router = $this->scan();
        $this->assertNotNull($router->match('GET', '/products'));
        $this->assertNotNull($router->match('HEAD', '/products'));
    }

    public function testMethodsWithoutAttributesAreSkipped(): void
    {
        $router = $this->scan();
        // `notARoute` has no #[Route], so there must be no path matching it.
        $this->assertNull($router->match('GET', '/notARoute'));
    }

    public function testScannerIgnoresUnknownClass(): void
    {
        $router = new Router();
        (new Scanner(new Container()))->register($router, ['\\No\\Such\\Class']);
        $this->assertNull($router->match('GET', '/anything'));
    }
}
