<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Resource\Fixture;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Update DTO — every field optional (nullable / has default), so
 * PATCH bodies can omit anything that isn't changing. The
 * implementation merges only fields the client provided into the
 * stored row.
 */
final class UpdateWidget implements RequestDto
{
    #[Length(min: 1, max: 100)]
    public ?string $name = null;

    #[Min(0)]
    public ?int $price = null;

    #[InSet(['draft', 'published', 'archived'])]
    public ?string $status = null;
}
