<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Pattern;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Lab DTO that exercises every cell of the
 * (type × required × default × nullable) matrix the Binder claims
 * to handle. Used by BinderMatrixTest to assert that every cell
 * behaves identically under `bind()` and `compileFor()` — the two
 * code paths must never diverge.
 */
final class BindMatrixDto implements RequestDto
{
    // --- required, no default, no null ---
    #[Required]
    public string $rs;

    #[Required]
    public int $ri;

    #[Required]
    public float $rf;

    #[Required]
    public bool $rb;

    // --- optional with default ---
    public string $os = 'fallback';
    public int $oi = 42;
    public float $of = 1.5;
    public bool $ob = true;

    // --- nullable, no default (falls back to null when missing) ---
    public ?string $ns = null;
    public ?int $ni = null;
    public ?float $nf = null;

    // --- attribute combos ---
    #[Required]
    #[Length(min: 2, max: 5)]
    public string $sized;

    #[Required]
    #[Min(10)]
    public int $atLeast10;

    #[Required]
    #[Pattern('/^[a-z]+$/')]
    public string $lowerOnly;

    #[Required]
    #[InSet(['a', 'b', 'c'])]
    public string $oneOf;
}
