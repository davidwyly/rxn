<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * `#[Length]` with no bounds. Both `min` and `max` are null, so
 * the Binder validator is a no-op. The exporter must skip emitting
 * the constraint rather than producing `length: { }`.
 */
final class EmptyLengthDto implements RequestDto
{
    #[Length]
    public ?string $note = null;
}
