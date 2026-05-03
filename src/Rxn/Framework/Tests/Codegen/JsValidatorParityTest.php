<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\JsValidatorEmitter;
use Rxn\Framework\Codegen\Testing\ParityHarness;

/**
 * Cross-language validator parity test. Uses the extracted
 * `ParityHarness` to drive N adversarial inputs through both
 * the PHP `Binder::bind` and the emitted JS validator, asserts
 * agreement on the set of failing fields per input.
 *
 * Skipped when `node` isn't on PATH — the test is meaningful
 * only with both runtimes available.
 *
 * Compares the *set of failing fields*, not message text. PHP
 * and JS messages happen to be identical today; the parity
 * guarantee doesn't hinge on that.
 */
final class JsValidatorParityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ParityHarness::nodeAvailable()) {
            $this->markTestSkipped('node is not on PATH; cross-language parity is not testable in this environment');
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function dtos(): iterable
    {
        yield 'ParityDto'      => [Fixture\ParityDto::class];
        yield 'KitchenSinkDto' => [Fixture\KitchenSinkDto::class];
        yield 'NumericEdgeDto' => [Fixture\NumericEdgeDto::class];
        yield 'StringEdgeDto'  => [Fixture\StringEdgeDto::class];
    }

    #[DataProvider('dtos')]
    public function testValidatorAgreesWithPhpOnRandomInputs(string $dtoClass): void
    {
        $emitter = new JsValidatorEmitter();
        $result  = ParityHarness::run(
            dto:        $dtoClass,
            source:     $emitter->emit($dtoClass),
            invoke:     ParityHarness::nodeInvoker(),
            iterations: 10_000,
            extension:  'mjs',
        );
        $this->assertSame(0, $result->disagreements, $result->describe());
    }

    public function testEmitterRefusesUnsupportedAttribute(): void
    {
        $emitter = new JsValidatorEmitter();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Pattern.*no JS twin/i');
        $emitter->emit(Fixture\UnsupportedDto::class);
    }
}
