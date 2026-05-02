<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Event;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Rxn\Framework\Event\EventDispatcher;
use Rxn\Framework\Event\ListenerProvider;

final class EventDispatcherTest extends TestCase
{
    public function testImplementsPsrEventDispatcherInterface(): void
    {
        $this->assertInstanceOf(
            EventDispatcherInterface::class,
            new EventDispatcher(new ListenerProvider()),
        );
    }

    public function testDispatchReturnsTheSameEventInstance(): void
    {
        $event      = new \stdClass();
        $dispatcher = new EventDispatcher(new ListenerProvider());
        $this->assertSame($event, $dispatcher->dispatch($event));
    }

    public function testListenersFireInRegistrationOrder(): void
    {
        $provider = new ListenerProvider();
        $log      = [];
        $provider->listen(\stdClass::class, function () use (&$log): void { $log[] = 'a'; });
        $provider->listen(\stdClass::class, function () use (&$log): void { $log[] = 'b'; });
        $provider->listen(\stdClass::class, function () use (&$log): void { $log[] = 'c'; });

        (new EventDispatcher($provider))->dispatch(new \stdClass());
        $this->assertSame(['a', 'b', 'c'], $log);
    }

    public function testStoppableEventShortCircuits(): void
    {
        $provider = new ListenerProvider();
        $log      = [];

        $event = new class implements StoppableEventInterface {
            public bool $stopped = false;
            public function isPropagationStopped(): bool { return $this->stopped; }
        };

        $provider->listen($event::class, function () use (&$log): void { $log[] = 'a'; });
        $provider->listen($event::class, function ($e) use (&$log): void {
            $log[] = 'b';
            $e->stopped = true;
        });
        $provider->listen($event::class, function () use (&$log): void { $log[] = 'c'; });

        (new EventDispatcher($provider))->dispatch($event);
        $this->assertSame(['a', 'b'], $log, 'listeners after stop must not run');
    }

    public function testNonStoppableEventIgnoresStopMethodOnUnrelatedClasses(): void
    {
        $provider = new ListenerProvider();
        $log      = [];
        $event    = new class { public function isPropagationStopped(): bool { return true; } };
        $provider->listen($event::class, function () use (&$log): void { $log[] = 'a'; });
        $provider->listen($event::class, function () use (&$log): void { $log[] = 'b'; });

        (new EventDispatcher($provider))->dispatch($event);
        $this->assertSame(
            ['a', 'b'],
            $log,
            'a class with isPropagationStopped() but not implementing StoppableEventInterface must not short-circuit',
        );
    }

    public function testNoListenersIsANoOp(): void
    {
        $event      = new \stdClass();
        $event->x   = 7;
        $dispatcher = new EventDispatcher(new ListenerProvider());
        $result     = $dispatcher->dispatch($event);
        $this->assertSame($event, $result);
        $this->assertSame(7, $event->x);
    }
}
