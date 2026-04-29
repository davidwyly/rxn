<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Validates a string property as a YYYY-MM-DD date that round-trips
 * cleanly through `DateTimeImmutable`. Rejects loose `strtotime`
 * inputs ("tomorrow", "now") and out-of-range dates that PHP would
 * otherwise normalise (`2024-02-30` → `2024-03-01`).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Date implements Validates
{
    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value
            ? null
            : 'must be a valid date (YYYY-MM-DD)';
    }
}
