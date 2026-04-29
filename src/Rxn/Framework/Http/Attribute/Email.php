<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Validates a string property as an RFC 5322-ish email address
 * via PHP's `FILTER_VALIDATE_EMAIL`. Non-string values pass —
 * type coercion is the Binder's job; this attribute only
 * checks the format.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Email implements Validates
{
    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? null
            : 'must be a valid email address';
    }
}
