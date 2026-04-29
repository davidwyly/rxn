<?php declare(strict_types=1);

namespace Example\Products\Dto;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\NotBlank;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Attribute\Url;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Input DTO for `POST /products`.
 *
 * This single declaration drives **four** consumers in Rxn:
 *
 *   1. `Binder::bind(CreateProduct::class, $bag)` hydrates a typed
 *      instance from the request bag, casting strings to the
 *      declared property types.
 *   2. The same call runs every property-level validation
 *      attribute (`#[Required]`, `#[NotBlank]`, `#[Length]`,
 *      `#[Min]`, `#[InSet]`, `#[Url]`) and collects errors
 *      into a single `ValidationException`.
 *   3. `Binder::compileFor(CreateProduct::class)` reads the same
 *      reflection and emits straight-line PHP for the 6.4×
 *      compiled fast path (RoadRunner / Swoole / FrankenPHP).
 *   4. `Http\OpenApi\Generator` reads the same property types and
 *      attributes to emit the OpenAPI 3 schema for the request
 *      body. (Run `bin/rxn openapi` to see the result.)
 *
 * That's the framework's "schema as truth, multiple consumers"
 * principle in one DTO — adding a property here automatically
 * updates input binding, validation, the spec, and the compiled
 * hot path with no other config to touch.
 */
final class CreateProduct implements RequestDto
{
    #[Required]
    #[NotBlank]
    #[Length(min: 1, max: 100)]
    public string $name;

    #[Required]
    #[Min(0)]
    public float $price;

    #[InSet(['draft', 'published', 'archived'])]
    public string $status = 'draft';

    #[Url]
    public ?string $homepage = null;
}
