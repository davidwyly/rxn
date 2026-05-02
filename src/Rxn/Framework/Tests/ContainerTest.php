<?php declare(strict_types=1);

namespace Rxn\Framework\Tests;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Container;
use Rxn\Framework\Tests\Fixture\Container\Clock;
use Rxn\Framework\Tests\Fixture\Container\FrozenClock;
use Rxn\Framework\Tests\Fixture\Container\SystemClock;
use Rxn\Framework\Tests\Fixture\Container\Timestamper;
use Rxn\Framework\Tests\Fixture\Container\UserRepo;
use Rxn\Framework\Tests\Fixture\Container\MemoryUserRepo;
use Rxn\Framework\Tests\Fixture\Container\NeedsDefaultBag;

final class ContainerTest extends TestCase
{
    public function testBindsInterfaceToConcrete(): void
    {
        $c = new Container();
        $c->bind(UserRepo::class, MemoryUserRepo::class);

        $repo = $c->get(UserRepo::class);
        $this->assertInstanceOf(MemoryUserRepo::class, $repo);
    }

    public function testBindsInterfaceViaFactoryClosure(): void
    {
        $c = new Container();
        $c->bind(Clock::class, fn (Container $_) => new FrozenClock('2026-01-01'));

        $clock = $c->get(Clock::class);
        $this->assertInstanceOf(FrozenClock::class, $clock);
        $this->assertSame('2026-01-01', $clock->now());
    }

    public function testBoundConcreteIsAutowiredAsConstructorDep(): void
    {
        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);

        // Timestamper takes Clock in its constructor; autowiring must
        // resolve it via the binding, not try to instantiate the
        // interface.
        $stamper = $c->get(Timestamper::class);
        $this->assertInstanceOf(Timestamper::class, $stamper);
    }

    public function testRebindingOverwrites(): void
    {
        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);
        $c->bind(Clock::class, fn () => new FrozenClock('fixed'));

        $clock = $c->get(Clock::class);
        $this->assertInstanceOf(FrozenClock::class, $clock);
    }

    public function testFactoryReceivesContainer(): void
    {
        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);
        $c->bind(UserRepo::class, function (Container $inner) {
            $inner->get(Clock::class); // proves the container is passed
            return new MemoryUserRepo();
        });

        $this->assertInstanceOf(MemoryUserRepo::class, $c->get(UserRepo::class));
    }

    public function testUnboundInterfaceStillExplodes(): void
    {
        $c = new Container();
        $this->expectException(\Throwable::class);
        $c->get(UserRepo::class);
    }


    public function testDefaultObjectParameterIsNotSharedAcrossInstances(): void
    {
        $c = new Container();


        $fromDefaultA = $c->get(NeedsDefaultBag::class);
        $fromDefaultB = $c->get(NeedsDefaultBag::class);

        $this->assertNotSame($fromDefaultA->bag, $fromDefaultB->bag);
    }

    public function testBindReturnsSelfForChaining(): void
    {
        $c = new Container();
        $this->assertSame($c, $c->bind(Clock::class, SystemClock::class));
    }
}
