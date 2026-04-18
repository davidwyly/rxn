<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Max;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Pattern;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

final class CreateProduct implements RequestDto
{
    #[Required]
    #[Length(min: 1, max: 100)]
    public string $name;

    #[Required]
    #[Min(0)]
    #[Max(1_000_000)]
    public int $price;

    #[Pattern('/^[a-z0-9-]+$/')]
    public string $slug = 'default-slug';

    #[InSet(['draft', 'published', 'archived'])]
    public string $status = 'draft';

    public bool $featured = false;

    public ?string $note = null;
}
