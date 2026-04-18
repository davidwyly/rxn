<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * String length bounds in UTF-8 characters. Either `min` or
 * `max` may be omitted (null). Non-string values pass.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Length implements Validates
{
    public function __construct(
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {}

    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $len = mb_strlen($value);
        if ($this->min !== null && $len < $this->min) {
            return "must be at least {$this->min} characters";
        }
        if ($this->max !== null && $len > $this->max) {
            return "must be at most {$this->max} characters";
        }
        return null;
    }
}
