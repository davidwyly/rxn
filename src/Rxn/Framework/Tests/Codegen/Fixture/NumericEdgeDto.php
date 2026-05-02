<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Attribute\Max;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Numeric coercion edge-case fixture. Tests the PHP-vs-JS
 * agreement on number parsing nuances:
 *
 *   - Round-trip int guard ('123abc' rejected, '123' accepted)
 *   - Float scientific notation ('1e3', '1.5E-2')
 *   - Negative zero ('-0' both sides round to 0)
 *   - Whitespace-padded numerics
 *   - Leading-plus signs
 *   - Boolean to numeric coercion
 *   - Zero-bound vs Min/Max boundaries
 *
 * Adversarial input generator's randomNumeric() supplies inputs
 * that cover every case above.
 */
final class NumericEdgeDto implements RequestDto
{
    #[Required]
    public int $strictInt;

    #[Min(-100)]
    #[Max(100)]
    public int $boundedInt = 0;

    #[Required]
    public float $strictFloat;

    #[Min(-1.5)]
    #[Max(1.5)]
    public float $boundedFloat = 0.0;

    public ?int $optionalInt = null;

    public ?float $optionalFloat = null;
}
