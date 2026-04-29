<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Tests\Http\Binding\Fixture\CreateProduct;

final class BinderTest extends TestCase
{
    public function testHydratesTypedProperties(): void
    {
        $dto = Binder::bind(CreateProduct::class, [
            'name'     => 'Widget',
            'price'    => '1299',       // string → int cast
            'slug'     => 'widget-v2',
            'status'   => 'published',
            'featured' => 'true',       // string → bool cast
        ]);

        $this->assertSame('Widget', $dto->name);
        $this->assertSame(1299, $dto->price);
        $this->assertSame('widget-v2', $dto->slug);
        $this->assertSame('published', $dto->status);
        $this->assertTrue($dto->featured);
    }

    public function testUsesDefaultsWhenOmitted(): void
    {
        $dto = Binder::bind(CreateProduct::class, [
            'name'  => 'Widget',
            'price' => 10,
        ]);
        $this->assertSame('default-slug', $dto->slug);
        $this->assertSame('draft', $dto->status);
        $this->assertFalse($dto->featured);
        $this->assertNull($dto->note);
    }

    public function testRequiredFieldsFailWith422(): void
    {
        try {
            Binder::bind(CreateProduct::class, []);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $fields = array_column($e->errors(), 'field');
            $this->assertContains('name', $fields);
            $this->assertContains('price', $fields);
        }
    }

    public function testTypeMismatchIsReported(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'name'  => 'Widget',
                'price' => 'not-a-number',
            ]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertContains(
                ['field' => 'price', 'message' => 'type mismatch'],
                $errors
            );
        }
    }

    public function testMinBoundFails(): void
    {
        try {
            Binder::bind(CreateProduct::class, ['name' => 'W', 'price' => -5]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame([['field' => 'price', 'message' => 'must be >= 0']], $e->errors());
        }
    }

    public function testMaxBoundFails(): void
    {
        try {
            Binder::bind(CreateProduct::class, ['name' => 'W', 'price' => 10_000_000]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame([['field' => 'price', 'message' => 'must be <= 1000000']], $e->errors());
        }
    }

    public function testLengthAboveMaxFails(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'name'  => str_repeat('a', 101),
                'price' => 1,
            ]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame([['field' => 'name', 'message' => 'must be at most 100 characters']], $e->errors());
        }
    }

    public function testPatternFails(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'name'  => 'W',
                'price' => 1,
                'slug'  => 'NOT LOWERCASE',
            ]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame(
                [['field' => 'slug', 'message' => 'does not match required pattern']],
                $e->errors()
            );
        }
    }

    public function testInSetRejectsUnknown(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'name'   => 'W',
                'price'  => 1,
                'status' => 'pending',
            ]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame(
                [['field' => 'status', 'message' => "must be one of: 'draft', 'published', 'archived'"]],
                $e->errors()
            );
        }
    }

    public function testCollectsEveryErrorAtOnce(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'price' => -1,
                'slug'  => 'BAD SLUG',
                'status' => 'pending',
            ]);
            $this->fail();
        } catch (ValidationException $e) {
            $fields = array_column($e->errors(), 'field');
            sort($fields);
            // name (missing), price (<0), slug (bad pattern), status (not in set)
            $this->assertSame(['name', 'price', 'slug', 'status'], $fields);
        }
    }

    public function testFailsOnNonDtoClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional misuse */
        Binder::bind(\stdClass::class, []);
    }

    public function testBoolCastAcceptsCommonTruthyStrings(): void
    {
        foreach (['true', '1', 'yes', 'on'] as $truthy) {
            $dto = Binder::bind(CreateProduct::class, [
                'name'     => 'W',
                'price'    => 1,
                'featured' => $truthy,
            ]);
            $this->assertTrue($dto->featured, "'$truthy' should cast to true");
        }
        foreach (['false', '0', 'no', 'off'] as $falsy) {
            $dto = Binder::bind(CreateProduct::class, [
                'name'     => 'W',
                'price'    => 1,
                'featured' => $falsy,
            ]);
            $this->assertFalse($dto->featured, "'$falsy' should cast to false");
        }
    }

    public function testReadsFromMergedSuperglobalsByDefault(): void
    {
        $prevGet  = $_GET;
        $prevPost = $_POST;
        try {
            $_GET  = ['name' => 'fromQuery'];
            $_POST = ['price' => 99];   // POST overrides GET on conflicts
            $dto   = Binder::bind(CreateProduct::class);
            $this->assertSame('fromQuery', $dto->name);
            $this->assertSame(99, $dto->price);
        } finally {
            $_GET  = $prevGet;
            $_POST = $prevPost;
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function compileParityCases(): iterable
    {
        yield 'all valid' => [[
            'name'     => 'Widget',
            'price'    => '1299',
            'slug'     => 'widget-v2',
            'status'   => 'published',
            'featured' => 'true',
            'note'     => 'a note',
        ]];

        yield 'optional fields default' => [[
            'name'  => 'Widget',
            'price' => 100,
        ]];

        yield 'all invalid' => [[
            'name'     => '',                   // empty -> required fires
            'price'    => 'free',                // not numeric
            'slug'     => 'BAD slug',            // pattern fail
            'status'   => 'unknown',             // not in set
            'featured' => 'maybe',               // bool cast fail
        ]];

        yield 'mins / maxes / lengths' => [[
            'name'   => str_repeat('A', 200),    // length max:100 fails
            'price'  => -1,                      // min:0 fails
            'slug'   => 'ok-slug',
            'status' => 'draft',
        ]];

        yield 'edge: nullable note explicit null' => [[
            'name'  => 'X',
            'price' => 1,
            'note'  => null,
        ]];
    }

    /**
     * Compiled binder must produce identical hydrated state and identical
     * error sets to the runtime path. Both forms catch the exception and
     * compare the collected errors when validation fails.
     *
     * @dataProvider compileParityCases
     * @param array<string, mixed> $bag
     */
    public function testCompiledOutputMatchesRuntimeOutput(array $bag): void
    {
        $runtimeError = null;
        $compiledError = null;
        $runtimeDto = null;
        $compiledDto = null;
        try {
            $runtimeDto = Binder::bind(CreateProduct::class, $bag);
        } catch (ValidationException $e) {
            $runtimeError = $e->errors();
        }
        $bind = Binder::compileFor(CreateProduct::class);
        try {
            $compiledDto = $bind($bag);
        } catch (ValidationException $e) {
            $compiledError = $e->errors();
        }
        if ($runtimeError !== null || $compiledError !== null) {
            $this->assertSame($runtimeError, $compiledError, 'error sets diverged');
            return;
        }
        $this->assertEquals($runtimeDto, $compiledDto, 'hydrated state diverged');
    }

    public function testCompileForCachesPerClass(): void
    {
        $a = Binder::compileFor(CreateProduct::class);
        $b = Binder::compileFor(CreateProduct::class);
        $this->assertSame($a, $b);
    }
}
