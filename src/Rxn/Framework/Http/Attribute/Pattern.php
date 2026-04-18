<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Regex match for string properties. The regex is used verbatim —
 * callers supply the delimiters and flags.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Pattern implements Validates
{
    public function __construct(public readonly string $regex) {}

    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return preg_match($this->regex, $value) === 1
            ? null
            : "does not match required pattern";
    }
}
