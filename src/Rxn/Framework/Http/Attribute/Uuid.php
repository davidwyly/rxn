<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Validates a string property as an RFC 4122 UUID — any version,
 * hyphenated, case-insensitive. Non-string values pass.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Uuid implements Validates
{
    /** @internal Shared with Binder's compiled-path inliner. */
    public const REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return preg_match(self::REGEX, $value) === 1
            ? null
            : 'must be a valid UUID';
    }
}
