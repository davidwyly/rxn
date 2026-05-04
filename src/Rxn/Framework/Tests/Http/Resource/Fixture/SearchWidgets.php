<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Resource\Fixture;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Filter shape for `GET /widgets`. Each field is optional; the
 * handler interprets the populated subset as a filter predicate.
 */
final class SearchWidgets implements RequestDto
{
    public ?string $q = null;

    #[InSet(['draft', 'published', 'archived'])]
    public ?string $status = null;

    #[Min(1)]
    public int $page = 1;
}
