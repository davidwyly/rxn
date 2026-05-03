<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Attribute;
use Rxn\Framework\Http\Binding\Validates;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class CustomValidatesAttribute implements Validates
{
    public function validate(mixed $value): ?string
    {
        return null;
    }
}
