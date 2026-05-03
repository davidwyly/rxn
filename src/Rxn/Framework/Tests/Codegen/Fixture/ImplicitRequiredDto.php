<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Property is non-nullable with no default and no `#[Required]` —
 * Binder treats this as effectively required (see the missing-field
 * branch in Binder::bind that emits "is required" when the property
 * has neither a default nor a nullable type).
 *
 * The exporter must mirror this so the polyparity spec doesn't
 * accept inputs the PHP server rejects.
 */
final class ImplicitRequiredDto implements RequestDto
{
    public string $code;
}
