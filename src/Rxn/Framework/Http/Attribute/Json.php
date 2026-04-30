<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Validates that a string property is parseable JSON via
 * `json_validate()` (PHP 8.3+). Non-string values pass.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Json implements Validates
{
    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return json_validate($value) ? null : 'must be valid JSON';
    }
}
