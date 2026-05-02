<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\NotBlank;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * String coercion edge-case fixture. Tests the PHP-vs-JS
 * agreement on string handling nuances:
 *
 *   - mb_strlen vs JS [...str].length agreement on multi-byte
 *     characters (the spread-iterator counts code points, which
 *     matches mb_strlen for the BMP and standard surrogate pairs)
 *   - Boolean to string coercion: PHP (string)true -> '1',
 *     (string)false -> ''
 *   - Number to string coercion: PHP cast preserves the integer
 *     repr, JS String() agrees for finite ints
 *   - Trim semantics: PHP's trim() and JS's trim() agree on
 *     ASCII whitespace; both lack trimming for U+200B etc.
 *   - Length boundary edges (0, 1, exactly max, max+1)
 *
 * Boundary-only fixture: short strings + tight Length bounds so
 * the adversarial generator's str_repeat('y', 100) and
 * str_repeat('z', 101) inputs reliably probe the boundary.
 */
final class StringEdgeDto implements RequestDto
{
    #[Required]
    #[NotBlank]
    #[Length(min: 1, max: 10)]
    public string $shortName;

    #[Length(min: 5, max: 5)]
    public string $exactFive = 'fives';

    public ?string $optionalText = null;
}
