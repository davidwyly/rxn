<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Fixture\Container;

final class NeedsDefaultBag
{
    public function __construct(public object $bag = new DefaultBag) {}
}
