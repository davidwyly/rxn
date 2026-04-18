<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Enum-like membership check — the value must be one of `$values`.
 * Useful for status columns, role names, etc. without pulling in
 * a real PHP enum.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class InSet implements Validates
{
    /** @param list<int|string|float|bool> $values */
    public function __construct(public readonly array $values) {}

    public function validate(mixed $value): ?string
    {
        if (in_array($value, $this->values, true)) {
            return null;
        }
        $allowed = implode(', ', array_map(
            static fn ($v) => is_string($v) ? "'$v'" : (string)$v,
            $this->values
        ));
        return "must be one of: $allowed";
    }
}
