<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * String property must start with `$prefix`. Non-string values pass.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class StartsWith implements Validates
{
    public function __construct(public readonly string $prefix) {}

    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return str_starts_with($value, $this->prefix)
            ? null
            : "must start with '{$this->prefix}'";
    }
}
