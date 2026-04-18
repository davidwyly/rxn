<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Fixture\Container;

final class MemoryUserRepo implements UserRepo
{
    public function count(): int
    {
        return 0;
    }
}
