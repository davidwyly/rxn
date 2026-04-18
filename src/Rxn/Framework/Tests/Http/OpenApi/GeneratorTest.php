<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\OpenApi;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\OpenApi\Generator;
use Rxn\Framework\Tests\Http\OpenApi\Fixture\v1\ProductsController;
use Rxn\Framework\Tests\Http\OpenApi\Fixture\v2\OrderItemsController;

final class GeneratorTest extends TestCase
{
    public function testGenerateProducesBasicEnvelope(): void
    {
        $spec = (new Generator(
            info: ['title' => 'Test', 'version' => '1.2.3'],
            servers: [['url' => 'https://api.example.com']],
            controllers: [ProductsController::class],
        ))->generate();

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame(['title' => 'Test', 'version' => '1.2.3'], $spec['info']);
        $this->assertSame([['url' => 'https://api.example.com']], $spec['servers']);
        $this->assertArrayHasKey('RxnSuccess', $spec['components']['schemas']);
        $this->assertArrayHasKey('ProblemDetails', $spec['components']['schemas']);
        // The 7807 fields are the non-negotiable parts.
        $pd = $spec['components']['schemas']['ProblemDetails']['properties'];
        foreach (['type', 'title', 'status', 'detail', 'instance'] as $f) {
            $this->assertArrayHasKey($f, $pd);
        }
    }

    public function testOperationPathAndIdFollowConvention(): void
    {
        $spec = (new Generator(controllers: [ProductsController::class]))->generate();
        $this->assertArrayHasKey('/v1.1/products/show', $spec['paths']);
        $op = $spec['paths']['/v1.1/products/show']['get'];
        $this->assertSame('products.show.v1.1', $op['operationId']);
        $this->assertSame(['products'], $op['tags']);
    }

    public function testCamelControllerNameIsSlugified(): void
    {
        $spec = (new Generator(controllers: [OrderItemsController::class]))->generate();
        $this->assertArrayHasKey('/v2.3/order_items/ship', $spec['paths']);
    }

    public function testInjectedDependenciesAreExcludedFromParameters(): void
    {
        $spec = (new Generator(controllers: [ProductsController::class]))->generate();
        $op = $spec['paths']['/v1.1/products/show']['get'];
        $names = array_column($op['parameters'], 'name');
        $this->assertContains('id', $names);
        $this->assertNotContains('request', $names); // Request object is DI
        $this->assertNotContains('database', $names);
    }

    public function testScalarTypesMapToOpenapiTypes(): void
    {
        $spec = (new Generator(controllers: [ProductsController::class]))->generate();
        $op = $spec['paths']['/v1.1/products/show']['get'];
        $byName = [];
        foreach ($op['parameters'] as $p) {
            $byName[$p['name']] = $p['schema']['type'];
        }
        $this->assertSame('integer', $byName['id']);
        $this->assertSame('string', $byName['filter']);
        $this->assertSame('boolean', $byName['verbose']);
    }

    public function testOptionalParametersAreNotRequired(): void
    {
        $spec = (new Generator(controllers: [ProductsController::class]))->generate();
        $op = $spec['paths']['/v1.1/products/show']['get'];
        $byName = [];
        foreach ($op['parameters'] as $p) {
            $byName[$p['name']] = $p['required'];
        }
        $this->assertTrue($byName['id']);
        $this->assertFalse($byName['verbose']);
    }

    public function testInheritedMethodsAreNotEmitted(): void
    {
        $spec = (new Generator(controllers: [ProductsController::class]))->generate();
        // ProductsController extends a base that has a `parentOnly_v1`
        // method; only locally-declared actions should appear.
        foreach ($spec['paths'] as $path => $_) {
            $this->assertStringNotContainsString('parent_only', $path);
        }
    }

    public function testPathsAreSorted(): void
    {
        $spec = (new Generator(controllers: [
            OrderItemsController::class,
            ProductsController::class,
        ]))->generate();
        $keys = array_keys($spec['paths']);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    public function testUnknownControllerIsIgnored(): void
    {
        $spec = (new Generator(controllers: ['\\No\\Such\\Class']))->generate();
        $this->assertSame([], $spec['paths']);
    }
}
