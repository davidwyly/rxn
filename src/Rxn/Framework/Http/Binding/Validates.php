<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Binding;

/**
 * Contract every validation attribute implements. Returning null
 * means "value is fine"; returning a string means "value failed,
 * here's the message". The property-cast layer runs first, so
 * implementations receive values already coerced to the
 * property's declared PHP type.
 */
interface Validates
{
    public function validate(mixed $value): ?string;
}
