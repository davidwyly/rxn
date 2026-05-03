<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Invalid attribute usage: `#[Min]` with a string argument.
 * Min's constructor is typed `int|float` with strict_types, so
 * `newInstance()` throws a TypeError at runtime — Binder rejects
 * this too. The exporter must surface the same rejection with a
 * clear message rather than a confusing return-type TypeError.
 */
final class StringMinDto implements RequestDto
{
    /** @phpstan-ignore-next-line intentional misuse for the test */
    #[Min('5')]
    public int $n;
}
