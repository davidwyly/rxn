<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Resource\Fixture;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

final class CreateWidget implements RequestDto
{
    #[Required]
    #[Length(min: 1, max: 100)]
    public string $name;

    #[Required]
    #[Min(0)]
    public int $price;

    #[InSet(['draft', 'published', 'archived'])]
    public string $status = 'draft';
}
