<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\JsValidatorEmitter;
use Rxn\Framework\Codegen\Testing\ParityHarness;

/**
 * Cross-language validator parity test. Now powered by the
 * extracted `ParityHarness` — every plugin in the cross-language
 * family will use the same harness, so this test doubles as the
 * harness's own self-verification.
 *
 * Skipped when `node` isn't on PATH — the test is meaningful only
 * with both runtimes available. CI image must install Node ≥ 18.
 *
 * Compares the *set of failing fields*, not message text. PHP and
 * JS messages happen to be identical today; the parity guarantee
 * doesn't hinge on that.
 */
final class JsValidatorParityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ParityHarness::nodeAvailable()) {
            $this->markTestSkipped('node is not on PATH; cross-language parity is not testable in this environment');
        }
    }

    public function testParityDtoAgreesWithPhpOnRandomInputs(): void
    {
        $emitter = new JsValidatorEmitter();
        $result = ParityHarness::run(
            dto:        Fixture\ParityDto::class,
            source:     $emitter->emit(Fixture\ParityDto::class),
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
