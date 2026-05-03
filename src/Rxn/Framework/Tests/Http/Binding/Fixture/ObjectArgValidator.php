<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

use Rxn\Framework\Http\Binding\Validates;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ObjectArgValidator implements Validates
{
    public function __construct(private readonly RuleObj $rule) {}

    public function validate(mixed $value): ?string
    {
        return $value === $this->rule->expected ? null : 'must match expected';
    }
}
