<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Fixture\Container;

final class SystemClock implements Clock
{
    public function now(): string
    {
        return date('Y-m-d');
    }
}
