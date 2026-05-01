<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\OpenApi;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\OpenApi\Generator;
use Rxn\Framework\Tests\Http\OpenApi\Fixture\v1\ProductsController;
use Rxn\Framework\Tests\Http\OpenApi\Fixture\v1\WidgetsController;
use Rxn\Framework\Tests\Http\OpenApi\Fixture\v2\OrderItemsController;

final class GeneratorTest extends TestCase
{
    public function testGeneratedSpecMatchesPinnedShape(): void
    {
        // Snapshot the cross-controller spec so that any structural
        // change to the OpenAPI generator becomes a deliberate diff
        // — DTO attributes round-trip to JSON Schema (Min → minimum,
        // Length → minLength/maxLength, InSet → enum) and the
        // framework promises that mapping doesn't drift. The exact
        // shape lives in Generator.php; this test is the spec-level
        // contract.
        $spec = (new Generator(
            info: ['title' => 'Pinned', 'version' => '1.0.0'],
            controllers: [
                ProductsController::class,
                WidgetsController::class,
                OrderItemsController::class,
            ],
        ))->generate();

        // Top-level shape.
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Pinned', $spec['info']['title']);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);

        // Every action shows up at the convention path with the
        // versioned operation id and a tag derived from controller.
        $expectedPaths = [
            '/v1.1/products/show',
            '/v1.1/widgets/list',
            '/v1.1/widgets/create',
            '/v2.3/order_items/ship',
        ];
        foreach ($expectedPaths as $path) {
            $this->assertArrayHasKey(
                $path,
                $spec['paths'],
                "OpenAPI snapshot drift: missing path '$path'"
            );
        }

        // The non-negotiable Problem Details schema is always present
        // and carries the RFC 7807 fields. If any of these disappear,
        // the framework's RFC compliance has silently regressed.
        $pd = $spec['components']['schemas']['ProblemDetails']['properties'];
        foreach (['type', 'title', 'status', 'detail', 'instance'] as $f) {
            $this->assertArrayHasKey($f, $pd);
        }

        // Spec must be JSON-encodable (the bin/rxn openapi command
        // emits this; it can never carry a non-encodable value).
        $json = json_encode($spec, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($spec, $decoded);
    }

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

    public function testDtoParameterFlipsOperationToPostWithRequestBody(): void
    {
        $spec = (new Generator(controllers: [WidgetsController::class]))->generate();
        $op   = $spec['paths']['/v1.1/widgets/create'];

        $this->assertArrayHasKey('post', $op);
        $this->assertArrayNotHasKey('get', $op);
        $this->assertTrue($op['post']['requestBody']['required']);
        $this->assertSame(
            ['$ref' => '#/components/schemas/CreateWidget'],
            $op['post']['requestBody']['content']['application/json']['schema']
        );
    }

    public function testNonDtoMethodStaysOnGetWithQueryParams(): void
    {
        $spec = (new Generator(controllers: [WidgetsController::class]))->generate();
        $op   = $spec['paths']['/v1.1/widgets/list'];

        $this->assertArrayHasKey('get', $op);
        $this->assertArrayNotHasKey('requestBody', $op['get']);
        $names = array_column($op['get']['parameters'], 'name');
        $this->assertSame(['page'], $names);
    }

    public function testDtoSchemaIsEmittedWithRequiredAndProperties(): void
    {
        $spec   = (new Generator(controllers: [WidgetsController::class]))->generate();
        $schema = $spec['components']['schemas']['CreateWidget'];

        $this->assertSame('object', $schema['type']);
        // name + price are #[Required]; price has a default? No —
        // non-default non-nullable int is implicitly required even
        // without the attribute. Both should appear.
        $this->assertContains('name', $schema['required']);
        $this->assertContains('price', $schema['required']);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('price', $schema['properties']);
        $this->assertArrayHasKey('slug', $schema['properties']);
    }

    public function testLengthAttributesBecomeOpenapiLengthKeywords(): void
    {
        $spec   = (new Generator(controllers: [WidgetsController::class]))->generate();
        $name   = $spec['components']['schemas']['CreateWidget']['properties']['name'];

        $this->assertSame('string', $name['type']);
        $this->assertSame(1, $name['minLength']);
        $this->assertSame(100, $name['maxLength']);
    }

    public function testMinMaxBecomeOpenapiNumericBounds(): void
    {
        $spec  = (new Generator(controllers: [WidgetsController::class]))->generate();
        $price = $spec['components']['schemas']['CreateWidget']['properties']['price'];

        $this->assertSame('integer', $price['type']);
        $this->assertSame(0, $price['minimum']);
        $this->assertSame(1_000_000, $price['maximum']);
    }

    public function testPatternAttributeStripsDelimiters(): void
    {
        $spec = (new Generator(controllers: [WidgetsController::class]))->generate();
        $slug = $spec['components']['schemas']['CreateWidget']['properties']['slug'];

        $this->assertSame('^[a-z0-9-]+$', $slug['pattern']);
    }

    public function testInSetBecomesEnum(): void
    {
        $spec   = (new Generator(controllers: [WidgetsController::class]))->generate();
        $status = $spec['components']['schemas']['CreateWidget']['properties']['status'];

        $this->assertSame(['draft', 'published', 'archived'], $status['enum']);
        $this->assertSame('draft', $status['default']);
    }

    public function testNullablePropertyMarksSchemaNullable(): void
    {
        $spec = (new Generator(controllers: [WidgetsController::class]))->generate();
        $note = $spec['components']['schemas']['CreateWidget']['properties']['note'];

        $this->assertTrue($note['nullable']);
        $this->assertNotContains('note', $spec['components']['schemas']['CreateWidget']['required'] ?? []);
    }

    public function testDefaultValueSurfacedOnSchema(): void
    {
        $spec     = (new Generator(controllers: [WidgetsController::class]))->generate();
        $featured = $spec['components']['schemas']['CreateWidget']['properties']['featured'];

        $this->assertSame('boolean', $featured['type']);
        $this->assertFalse($featured['default']);
    }
}
