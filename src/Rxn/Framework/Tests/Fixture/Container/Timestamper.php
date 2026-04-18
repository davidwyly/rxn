<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Fixture\Container;

final class Timestamper
{
    public function __construct(private Clock $clock) {}

    public function stamp(string $what): string
    {
        return $what . '@' . $this->clock->now();
    }
}
