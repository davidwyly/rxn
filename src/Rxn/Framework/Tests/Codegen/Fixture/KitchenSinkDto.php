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
 * Maximum-coverage parity fixture. Combines every in-scope
 * attribute, every scalar type the emitter handles, and the
 * Required/default/nullable/optional matrix in one place.
 *
 * What's exercised here that ParityDto doesn't cover:
 *
 *   - Required + default-eligible (default ignored when Required)
 *   - Float with both Min and Max
 *   - Multiple Length-bounded strings with different bounds
 *   - Two InSets in the same DTO with different value sets
 *   - Stacked NotBlank + Length on the same field
 *   - Url + Email on the same DTO
 *   - Integer with both Min and Max bounds
 *   - Optional bool (no default; value either present or absent)
 *
 * Same parity guarantee applies: at 10K random adversarial
 * inputs, PHP and JS validators must agree on the failing-field
 * set for every input.
 */
final class KitchenSinkDto implements RequestDto
{
    #[Required]
    #[NotBlank]
    #[Length(min: 3, max: 50)]
    public string $title;

    #[Length(max: 500)]
    public string $description = '';

    #[Required]
    #[Min(0)]
    #[Max(1_000_000)]
    public int $quantity;

    #[Required]
    #[Min(0.01)]
    #[Max(99_999.99)]
    public float $unitPrice;

    #[InSet(['USD', 'EUR', 'GBP', 'JPY'])]
    public string $currency = 'USD';

    #[InSet(['draft', 'review', 'approved', 'rejected'])]
    public string $state = 'draft';

    public bool $taxable = true;

    public ?bool $featured = null;

    #[Url]
    public ?string $imageUrl = null;

    #[Email]
    public ?string $contact = null;

    #[Length(min: 2, max: 2)]
    public ?string $countryCode = null;
}
