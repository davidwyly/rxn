<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Snapshot;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\Snapshot\OpenApiSnapshot;
use Rxn\Framework\Codegen\Snapshot\SnapshotChange;

/**
 * Unit tests for the OpenAPI snapshot serialiser + diff classifier.
 * The serialiser must be byte-stable across PHP versions / machines
 * (otherwise the snapshot file becomes useless), and the diff must
 * classify each kind of structural change as either breaking or
 * additive — false negatives on breaking changes defeat the gate's
 * purpose.
 */
final class OpenApiSnapshotTest extends TestCase
{
    public function testSerialiseProducesStableOutputRegardlessOfInputKeyOrder(): void
    {
        $a = [
            'paths' => ['/x' => ['get' => ['summary' => 'x']]],
            'info'  => ['title' => 'A', 'version' => '1'],
        ];
        $b = [
            'info'  => ['version' => '1', 'title' => 'A'],
            'paths' => ['/x' => ['get' => ['summary' => 'x']]],
        ];

        $sa = OpenApiSnapshot::serialise($a);
        $sb = OpenApiSnapshot::serialise($b);

        $this->assertSame($sa, $sb, 'Two specs differing only in key order must serialise identically');
        $this->assertStringEndsWith("\n", $sa, 'Serialised output must end with a trailing newline for POSIX-friendly diffs');
    }

    public function testSerialiseDoesNotReorderListsInsideMaps(): void
    {
        // A `parameters` array is a list, not a map. Reordering it
        // would lose information (the spec treats list order as
        // significant for some constructs and meaningless for others;
        // we don't make that distinction — we don't touch lists).
        $spec = [
            'paths' => [
                '/x' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'b', 'in' => 'query'],
                            ['name' => 'a', 'in' => 'query'],
                        ],
                    ],
                ],
            ],
        ];
        $serialised = OpenApiSnapshot::serialise($spec);
        // 'b' must precede 'a' in the serialised output — list order preserved.
        $posA = strpos($serialised, '"a"');
        $posB = strpos($serialised, '"b"');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posA, $posB, 'list order must be preserved in serialised output');
    }

    public function testDiffOnIdenticalSpecsIsClean(): void
    {
        $spec = self::sampleSpec();
        $diff = OpenApiSnapshot::diff($spec, $spec);
        $this->assertTrue($diff->isClean());
        $this->assertSame([], $diff->all());
    }

    public function testDetectsRemovedOperationAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        unset($new['paths']['/v1/products']['get']);

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertCount(1, $diff->breaking());
        $this->assertSame('paths./v1/products.get', $diff->breaking()[0]->path);
        $this->assertSame('operation removed', $diff->breaking()[0]->message);
    }

    public function testDetectsAddedOperationAsAdditive(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $new['paths']['/v1/products']['delete'] = ['summary' => 'delete'];

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertFalse($diff->hasBreaking());
        $this->assertCount(1, $diff->additive());
        $this->assertSame('paths./v1/products.delete', $diff->additive()[0]->path);
    }

    public function testDetectsRemovedPathAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        unset($new['paths']['/v1/products']);

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertSame('path removed', $diff->breaking()[0]->message);
    }

    public function testDetectsRequiredParameterAddedAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $new['paths']['/v1/products']['get']['parameters'][] = [
            'name' => 'x_api_key',
            'in' => 'header',
            'required' => true,
            'schema' => ['type' => 'string'],
        ];

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertSame('required parameter added', $diff->breaking()[0]->message);
    }

    public function testDetectsOptionalParameterAddedAsAdditive(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $new['paths']['/v1/products']['get']['parameters'][] = [
            'name' => 'fields',
            'in' => 'query',
            'required' => false,
            'schema' => ['type' => 'string'],
        ];

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertFalse($diff->hasBreaking());
        $this->assertCount(1, $diff->additive());
    }

    public function testDetectsParameterTypeChangeAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $new['paths']['/v1/products']['get']['parameters'][0]['schema']['type'] = 'string';

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertStringContainsString('type changed from integer to string', $diff->breaking()[0]->message);
    }

    public function testDetectsParameterBecameRequiredAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $new['paths']['/v1/products']['get']['parameters'][0]['required'] = true;

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertSame('parameter became required', $diff->breaking()[0]->message);
    }

    public function testDetectsRemovedParameterAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        // Drop the `id` parameter entirely.
        $new['paths']['/v1/products']['get']['parameters'] = [];

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertSame('parameter removed', $diff->breaking()[0]->message);
    }

    public function testDetectsRemovedSchemaAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        unset($new['components']['schemas']['Product']);

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertSame('schema removed', $diff->breaking()[0]->message);
    }

    public function testDetectsRemovedPropertyAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        unset($new['components']['schemas']['Product']['properties']['price']);

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertSame('property removed', $diff->breaking()[0]->message);
    }

    public function testDetectsAddedRequiredPropertyAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $new['components']['schemas']['Product']['properties']['sku'] = ['type' => 'string'];
        $new['components']['schemas']['Product']['required'][] = 'sku';

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertSame('required property added', $diff->breaking()[0]->message);
    }

    public function testDetectsAddedOptionalPropertyAsAdditive(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $new['components']['schemas']['Product']['properties']['thumbnail_url'] = ['type' => 'string'];

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertFalse($diff->hasBreaking());
        $this->assertCount(1, $diff->additive());
        $this->assertSame('optional property added', $diff->additive()[0]->message);
    }

    public function testDetectsPropertyBecameRequiredAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        // `status` exists in old as optional; flip to required.
        $new['components']['schemas']['Product']['required'][] = 'status';

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertStringContainsString("property 'status' became required", $diff->breaking()[0]->message);
    }

    public function testDetectsPropertyTypeChangeAsBreaking(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $new['components']['schemas']['Product']['properties']['price']['type'] = 'string';

        $diff = OpenApiSnapshot::diff($old, $new);
        $this->assertTrue($diff->hasBreaking());
        $this->assertStringContainsString('type changed from number to string', $diff->breaking()[0]->message);
    }

    public function testDescribeRendersBothBuckets(): void
    {
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        unset($new['paths']['/v1/products']['get']);
        $new['components']['schemas']['Product']['properties']['thumbnail_url'] = ['type' => 'string'];

        $diff = OpenApiSnapshot::diff($old, $new);
        $output = $diff->describe();

        $this->assertStringContainsString('Breaking changes (1)', $output);
        $this->assertStringContainsString('Additive changes (1)', $output);
        $this->assertStringContainsString('[breaking]', $output);
        $this->assertStringContainsString('[additive]', $output);
    }

    public function testNonOperationKeysOnPathsAreIgnored(): void
    {
        // OpenAPI lets the path-level object carry siblings to the
        // verb operations: `parameters`, `summary`, `description`,
        // `$ref`. The diff must not flag these as removed/added
        // operations.
        $old = self::sampleSpec();
        $new = self::sampleSpec();
        $old['paths']['/v1/products']['summary'] = 'Products endpoints';
        $new['paths']['/v1/products']['summary'] = 'Updated summary';

        $diff = OpenApiSnapshot::diff($old, $new);
        // Summary changes are intentionally not tracked — they're
        // documentation drift, not contract drift.
        $this->assertTrue($diff->isClean());
    }

    /** @return array<string, mixed> */
    private static function sampleSpec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Sample', 'version' => '1.0.0'],
            'paths' => [
                '/v1/products' => [
                    'get' => [
                        'summary' => 'List products',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                    ],
                    'post' => [
                        'summary' => 'Create product',
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Product' => [
                        'type' => 'object',
                        'properties' => [
                            'name'   => ['type' => 'string'],
                            'price'  => ['type' => 'number'],
                            'status' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'price'],
                    ],
                ],
            ],
        ];
    }
}
