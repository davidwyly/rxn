<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

final class ObjectArgDto implements RequestDto
{
    #[Required]
    #[ObjectArgValidator(new RuleObj('ok'))]
    public string $code;
}
