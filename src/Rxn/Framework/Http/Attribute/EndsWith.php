<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * String property must end with `$suffix`. Non-string values pass.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class EndsWith implements Validates
{
    public function __construct(public readonly string $suffix) {}

    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return str_ends_with($value, $this->suffix)
            ? null
            : "must end with '{$this->suffix}'";
    }
}
