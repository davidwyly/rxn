<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\PolyparityExporter;

/**
 * Snapshot tests for the polyparity YAML exporter. Each test
 * locks the exact YAML output for a given DTO so any drift in
 * either the exporter logic or the source DTO surfaces as an
 * explicit diff in the assertion.
 *
 * Out-of-scope attributes (Pattern / Uuid / Json / Date /
 * StartsWith / EndsWith) trigger refusal — same posture as the
 * `JsValidatorEmitter`. Silent divergence is the worst failure
 * mode.
 */
final class PolyparityExporterTest extends TestCase
{
    public function testEmitsParityDtoSpec(): void
    {
        $expected = <<<YAML
        version: 0.1
        schema:
          name: ParityDto
          fields:
            name:
              type: string
              required: true
              not_blank: true
              length: { min: 1, max: 100 }

            price:
              type: int
              required: true
              min: 0
              max: 1000000

            status:
              type: string
              default: draft
              one_of: [draft, published, archived]

            featured:
              type: bool
              default: false

            homepage:
              type: string
              nullable: true
              url: true

            email:
              type: string
              nullable: true
              email: true


        YAML;

        $actual = (new PolyparityExporter())->emit(Fixture\ParityDto::class);
        $this->assertSame($expected, $actual);
    }

    public function testEmitsKitchenSinkDtoSpec(): void
    {
        $expected = <<<YAML
        version: 0.1
        schema:
          name: KitchenSinkDto
          fields:
            title:
              type: string
              required: true
              not_blank: true
              length: { min: 3, max: 50 }

            description:
              type: string
              default: ''
              length: { max: 500 }

            quantity:
              type: int
              required: true
              min: 0
              max: 1000000

            unitPrice:
              type: float
              required: true
              min: 0.01
              max: 99999.99

            currency:
              type: string
              default: USD
              one_of: [USD, EUR, GBP, JPY]

            state:
              type: string
              default: draft
              one_of: [draft, review, approved, rejected]

            taxable:
              type: bool
              default: true

            featured:
              type: bool
              nullable: true

            imageUrl:
              type: string
              nullable: true
              url: true

            contact:
              type: string
              nullable: true
              email: true

            countryCode:
              type: string
              nullable: true
              length: { min: 2, max: 2 }


        YAML;

        $actual = (new PolyparityExporter())->emit(Fixture\KitchenSinkDto::class);
        $this->assertSame($expected, $actual);
    }

    public function testEmitsNumericEdgeDtoSpec(): void
    {
        $expected = <<<YAML
        version: 0.1
        schema:
          name: NumericEdgeDto
          fields:
            strictInt:
              type: int
              required: true

            boundedInt:
              type: int
              default: 0
              min: -100
              max: 100

            strictFloat:
              type: float
              required: true

            boundedFloat:
              type: float
              default: 0.0
              min: -1.5
              max: 1.5

            optionalInt:
              type: int
              nullable: true

            optionalFloat:
              type: float
              nullable: true


        YAML;

        $actual = (new PolyparityExporter())->emit(Fixture\NumericEdgeDto::class);
        $this->assertSame($expected, $actual);
    }

    public function testRefusesUnsupportedAttribute(): void
    {
        $exporter = new PolyparityExporter();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Pattern.*no polyparity twin/i');
        $exporter->emit(Fixture\UnsupportedDto::class);
    }

    public function testRejectsNonRequestDto(): void
    {
        $exporter = new PolyparityExporter();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must implement/i');
        $exporter->emit(\stdClass::class);
    }

    public function testEmitsImplicitRequiredForNonNullableNoDefault(): void
    {
        // Mirror Binder: a non-nullable property with no default and
        // no #[Required] is still effectively required (Binder emits
        // "is required" in the missing-field branch).
        $yaml = (new PolyparityExporter())->emit(Fixture\ImplicitRequiredDto::class);
        $this->assertStringContainsString(
            "    code:\n      type: string\n      required: true",
            $yaml,
            'non-nullable + no-default property must export as required: true',
        );
    }

    public function testSuppressesDefaultWhenRequiredIsTrue(): void
    {
        // Binder ignores the default when #[Required] is set (the
        // missing-field branch fires first with "is required"). The
        // exported spec must not include a misleading default.
        $yaml = (new PolyparityExporter())->emit(Fixture\RequiredWithDefaultDto::class);
        $this->assertStringContainsString('required: true', $yaml);
        $this->assertStringNotContainsString('default:', $yaml);
    }

    public function testSkipsLengthWithBothBoundsNull(): void
    {
        // `#[Length]` with no bounds is a Binder no-op. Emitting
        // `length: { }` would be at best vacuous, at worst invalid
        // for strict polyparity parsers.
        $yaml = (new PolyparityExporter())->emit(Fixture\EmptyLengthDto::class);
        $this->assertStringNotContainsString('length:', $yaml);
    }
}
