<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

use Rxn\Framework\Http\Binding\Validates;

/**
 * Test fixture: a non-inlinable validator attribute. The Binder
 * doesn't have a built-in inline path for this class, so it goes
 * through the side-table — which is exactly what the dump path's
 * `$validators = [...]` prelude has to reconstruct.
 *
 * Supports both positional and named constructor args so the
 * test suite can exercise both shapes through
 * `validatorConstructionExpr`.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class CountryCode implements Validates
{
    /** @param list<string> $allowed */
    public function __construct(
        public readonly array  $allowed = ['US', 'CA'],
        public readonly string $message = 'unsupported country code',
    ) {}

    public function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return $this->message;
        }
        return in_array($value, $this->allowed, true) ? null : $this->message;
    }
}
