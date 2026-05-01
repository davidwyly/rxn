<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Tests\Http\Binding\Fixture\BindMatrixDto;

/**
 * Parametric coverage of the (type × required × default × nullable)
 * matrix the Binder claims to handle, run twice — once through the
 * runtime `bind()` path, once through the eval-compiled
 * `compileFor()` path — to lock the two implementations against
 * drift. The "schema as truth, multiple consumers" claim only
 * holds if both consumers agree on every cell.
 */
final class BinderMatrixTest extends TestCase
{
    /**
     * Minimum bag that satisfies every #[Required] field. Cases
     * mutate this and assert that bind() / compileFor() produce
     * matching outcomes.
     *
     * @return array<string, mixed>
     */
    private static function validBag(): array
    {
        return [
            'rs'        => 'hello',
            'ri'        => '7',          // string → int cast
            'rf'        => '3.14',       // string → float cast
            'rb'        => 'true',       // string → bool cast
            'sized'     => 'abcd',
            'atLeast10' => '11',
            'lowerOnly' => 'abc',
            'oneOf'     => 'b',
        ];
    }

    /**
     * Each case: [bag, expectErrors] where expectErrors is null
     * (success) or list of fields that must appear in the
     * ValidationException's error set.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: ?list<string>}>
     */
    public static function cases(): iterable
    {
        $base = self::validBag();

        yield 'valid bag → success' => [$base, null];

        // --- required missing ---
        yield 'required string missing' => [
            array_diff_key($base, ['rs' => 1]),
            ['rs'],
        ];
        yield 'required int missing' => [
            array_diff_key($base, ['ri' => 1]),
            ['ri'],
        ];
        yield 'every required missing' => [
            ['oneOf' => 'b'], // keep one valid field so we don't hit a parse-side issue
            ['rs', 'ri', 'rf', 'rb', 'sized', 'atLeast10', 'lowerOnly'],
        ];
        // Empty string and null both count as "missing" per Binder
        // contract — required fields fail.
        yield 'required string is empty string' => [
            ['rs' => ''] + $base,
            ['rs'],
        ];
        yield 'required string is explicit null' => [
            ['rs' => null] + $base,
            ['rs'],
        ];

        // --- type cast failures ---
        yield 'int field gets non-numeric' => [
            ['ri' => 'banana'] + $base,
            ['ri'],
        ];
        yield 'float field gets garbage' => [
            ['rf' => 'banana'] + $base,
            ['rf'],
        ];

        // --- attribute violations ---
        yield 'sized too short' => [
            ['sized' => 'a'] + $base,
            ['sized'],
        ];
        yield 'sized too long' => [
            ['sized' => 'abcdefg'] + $base,
            ['sized'],
        ];
        yield 'atLeast10 below min' => [
            ['atLeast10' => '3'] + $base,
            ['atLeast10'],
        ];
        yield 'lowerOnly pattern fail' => [
            ['lowerOnly' => 'ABC'] + $base,
            ['lowerOnly'],
        ];
        yield 'oneOf not in set' => [
            ['oneOf' => 'd'] + $base,
            ['oneOf'],
        ];

        // --- multi-error collection ---
        yield 'multiple violations all surface at once' => [
            ['sized' => 'a', 'atLeast10' => '1', 'lowerOnly' => 'X'] + $base,
            ['sized', 'atLeast10', 'lowerOnly'],
        ];
    }

    /**
     * @param array<string, mixed> $bag
     * @param list<string>|null    $expectErrors
     */
    #[DataProvider('cases')]
    public function testRuntimePath(array $bag, ?array $expectErrors): void
    {
        $this->runCase(
            fn () => Binder::bind(BindMatrixDto::class, $bag),
            $expectErrors,
        );
    }

    /**
     * @param array<string, mixed> $bag
     * @param list<string>|null    $expectErrors
     */
    #[DataProvider('cases')]
    public function testCompiledPath(array $bag, ?array $expectErrors): void
    {
        $compiled = Binder::compileFor(BindMatrixDto::class);
        $this->runCase(fn () => $compiled($bag), $expectErrors);
    }

    /**
     * @param callable(): BindMatrixDto $invoke
     * @param list<string>|null         $expectErrors
     */
    private function runCase(callable $invoke, ?array $expectErrors): void
    {
        if ($expectErrors === null) {
            $dto = $invoke();
            $this->assertInstanceOf(BindMatrixDto::class, $dto);
            // Spot-check the cast outcomes — the typed properties
            // mean any incorrect cast would TypeError.
            $this->assertSame(7, $dto->ri);
            $this->assertSame(3.14, $dto->rf);
            $this->assertTrue($dto->rb);
            // Defaults survive when not in the bag.
            $this->assertSame('fallback', $dto->os);
            $this->assertNull($dto->ns);
            return;
        }

        try {
            $invoke();
            $this->fail('expected ValidationException, got success');
        } catch (ValidationException $e) {
            $fields = array_column($e->errors(), 'field');
            foreach ($expectErrors as $expected) {
                $this->assertContains(
                    $expected,
                    $fields,
                    "expected '$expected' in error fields, got " . implode(',', $fields),
                );
            }
            $this->assertSame(422, $e->getCode());
        }
    }

    public function testRuntimeAndCompiledProduceIdenticalErrorSets(): void
    {
        // For the same failing bag, the error fields must match
        // between paths. The order doesn't matter, but the set must.
        $bag = ['sized' => 'a', 'atLeast10' => '1', 'lowerOnly' => 'X'] + self::validBag();

        $runtimeErrors = null;
        try {
            Binder::bind(BindMatrixDto::class, $bag);
        } catch (ValidationException $e) {
            $runtimeErrors = array_column($e->errors(), 'field');
        }

        $compiled = Binder::compileFor(BindMatrixDto::class);
        $compiledErrors = null;
        try {
            $compiled($bag);
        } catch (ValidationException $e) {
            $compiledErrors = array_column($e->errors(), 'field');
        }

        sort($runtimeErrors);
        sort($compiledErrors);
        $this->assertSame($runtimeErrors, $compiledErrors);
    }
}
