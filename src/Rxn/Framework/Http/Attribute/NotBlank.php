<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * String property must contain at least one non-whitespace
 * character. Stronger than `Required` — `Required` allows
 * "   " (whitespace-only); `NotBlank` rejects it. Non-string
 * values pass.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class NotBlank implements Validates
{
    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return trim($value) !== '' ? null : 'must not be blank';
    }
}
