<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Validates a string property via `FILTER_VALIDATE_URL`. Non-string
 * values pass.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Url implements Validates
{
    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            ? null
            : 'must be a valid URL';
    }
}
