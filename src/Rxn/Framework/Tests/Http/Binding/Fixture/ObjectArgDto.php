<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

use Rxn\Framework\Http\Attribute\Required;

final class ObjectArgDto
{
    #[Required]
    #[ObjectArgValidator(new RuleObj('ok'))]
    public string $code;
}
