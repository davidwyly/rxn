<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Event;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;
use Rxn\Framework\Event\ListenerProvider;

interface FixtureLoggable {}

class FixtureBaseEvent {}

class FixtureChildEvent extends FixtureBaseEvent implements FixtureLoggable {}

final class ListenerProviderTest extends TestCase
{
    public function testImplementsPsrListenerProviderInterface(): void
    {
        $this->assertInstanceOf(ListenerProviderInterface::class, new ListenerProvider());
    }

    public function testReturnsListenersRegisteredForExactClass(): void
    {
        $provider = new ListenerProvider();
        $listener = static fn () => null;
        $provider->listen(FixtureBaseEvent::class, $listener);

        $listeners = iterator_to_array($provider->getListenersForEvent(new FixtureBaseEvent()), false);
        $this->assertCount(1, $listeners);
        $this->assertSame($listener, $listeners[0]);
    }

    public function testParentClassListenersAlsoFireForSubclass(): void
    {
        $provider = new ListenerProvider();
        $log      = [];
        $provider->listen(FixtureBaseEvent::class,  function () use (&$log): void { $log[] = 'parent'; });
        $provider->listen(FixtureChildEvent::class, function () use (&$log): void { $log[] = 'child';  });

        foreach ($provider->getListenersForEvent(new FixtureChildEvent()) as $listener) {
            $listener(new FixtureChildEvent());
        }
        $this->assertSame(['child', 'parent'], $log, 'concrete class listeners fire before parent listeners');
    }

    public function testInterfaceListenersFireForImplementers(): void
    {
        $provider = new ListenerProvider();
        $log      = [];
        $provider->listen(FixtureLoggable::class, function () use (&$log): void { $log[] = 'iface'; });

        foreach ($provider->getListenersForEvent(new FixtureChildEvent()) as $listener) {
            $listener(new FixtureChildEvent());
        }
        $this->assertSame(['iface'], $log);
    }

    public function testEventWithNoMatchingListenersYieldsEmpty(): void
    {
        $provider = new ListenerProvider();
        $listeners = iterator_to_array($provider->getListenersForEvent(new \stdClass()), false);
        $this->assertSame([], $listeners);
    }

    public function testRegistrationOrderWithinSameTypeBucket(): void
    {
        $provider = new ListenerProvider();
        $log      = [];
        $provider->listen(\stdClass::class, function () use (&$log): void { $log[] = 1; });
        $provider->listen(\stdClass::class, function () use (&$log): void { $log[] = 2; });
        $provider->listen(\stdClass::class, function () use (&$log): void { $log[] = 3; });

        foreach ($provider->getListenersForEvent(new \stdClass()) as $listener) {
            $listener(new \stdClass());
        }
        $this->assertSame([1, 2, 3], $log);
    }
}
