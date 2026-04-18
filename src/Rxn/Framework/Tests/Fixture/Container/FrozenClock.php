<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Fixture\Container;

final class FrozenClock implements Clock
{
    public function __construct(private string $value) {}

    public function now(): string
    {
        return $this->value;
    }
}
