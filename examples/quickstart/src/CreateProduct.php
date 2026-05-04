<?php declare(strict_types=1);

namespace Example;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Wire-shape contract for `POST /products`. Public typed
 * properties are populated by the Binder; attribute validation
 * runs before the handler sees the instance.
 *
 * The same DTO is also read by:
 *   - `Binder::compileFor()` — for the schema-compiled fast path
 *   - `Http\OpenApi\Generator` — for the auto-generated spec
 *   - the route conflict detector (`bin/rxn routes:check`)
 *
 * That's the framework's "schema as truth, multiple consumers"
 * principle in 25 lines of PHP.
 */
final class CreateProduct implements RequestDto
{
    #[Required]
    #[Length(min: 1, max: 100)]
    public string $name;

    #[Required]
    #[Min(0)]
    public int $price;

    #[InSet(['draft', 'published', 'archived'])]
    public string $status = 'draft';
}
