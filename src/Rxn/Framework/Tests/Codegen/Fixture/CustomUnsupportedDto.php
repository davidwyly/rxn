<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

final class CustomUnsupportedDto implements RequestDto
{
    #[Required]
    #[CustomValidatesAttribute]
    public string $token;
}
