<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Numeric lower bound — inclusive. Non-numeric values pass (type
 * coercion is the Binder's job; this attribute only checks range).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Min implements Validates
{
    public function __construct(public readonly int|float $min) {}

    public function validate(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        return $value < $this->min ? "must be >= {$this->min}" : null;
    }
}
