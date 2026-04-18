<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/** Numeric upper bound — inclusive. Counterpart to `Min`. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Max implements Validates
{
    public function __construct(public readonly int|float $max) {}

    public function validate(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        return $value > $this->max ? "must be <= {$this->max}" : null;
    }
}
