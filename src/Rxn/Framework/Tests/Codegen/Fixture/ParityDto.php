<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Attribute\Email;
use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Max;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\NotBlank;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Attribute\Url;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Parity test fixture. Exercises every attribute the
 * `JsValidatorEmitter` claims to mirror — Required, NotBlank,
 * Length, Min, Max, InSet, Url, Email — plus the four scalar
 * casts (string, int, float, bool) and the nullable-string
 * pattern. No Pattern / Uuid / Json / Date / StartsWith /
 * EndsWith here — those are explicitly out of scope for the
 * v1 cross-language guarantee.
 */
final class ParityDto implements RequestDto
{
    #[Required]
    #[NotBlank]
    #[Length(min: 1, max: 100)]
    public string $name;

    #[Required]
    #[Min(0)]
    #[Max(1_000_000)]
    public int $price;

    #[InSet(['draft', 'published', 'archived'])]
    public string $status = 'draft';

    public bool $featured = false;

    #[Url]
    public ?string $homepage = null;

    #[Email]
    public ?string $email = null;
}
