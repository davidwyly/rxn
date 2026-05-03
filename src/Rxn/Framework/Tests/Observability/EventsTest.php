<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rxn\Framework\Event\EventDispatcher;
use Rxn\Framework\Event\ListenerProvider;
use Rxn\Framework\Observability\Event\FrameworkEvent;
use Rxn\Framework\Observability\Events;

/**
 * Direct unit tests for the static dispatch slot. Two things on
 * trial:
 *
 *  1. `emit()` is a no-op (and returns the event unchanged) when
 *     no dispatcher is installed — every framework call site
 *     relies on this so adding the events doesn't impose runtime
 *     cost on apps that don't subscribe.
 *  2. `emit()` flows through to the installed dispatcher and
 *     returns whatever the dispatcher returns (so listener-mutated
 *     events are visible to the caller).
 */
final class EventsTest extends TestCase
{
    protected function setUp(): void
    {
        Events::useDispatcher(null);
    }

    protected function tearDown(): void
    {
        Events::useDispatcher(null);
    }

    public function testEmitIsNoopWhenNoDispatcherInstalled(): void
    {
        $event = new class implements FrameworkEvent {};
        // No exception, returns the same event unchanged.
        $this->assertSame($event, Events::emit($event));
    }

    public function testEmitDispatchesThroughInstalledDispatcher(): void
    {
        $captured = [];
        $provider = new ListenerProvider();
        $provider->listen(FrameworkEvent::class, static function (object $e) use (&$captured): void {
            $captured[] = $e;
        });
        Events::useDispatcher(new EventDispatcher($provider));

        $event = new class implements FrameworkEvent {};
        Events::emit($event);

        $this->assertSame([$event], $captured);
    }

    public function testDispatcherAccessorReturnsInstalledDispatcher(): void
    {
        $this->assertNull(Events::dispatcher());
        $dispatcher = new EventDispatcher(new ListenerProvider());
        Events::useDispatcher($dispatcher);
        $this->assertSame($dispatcher, Events::dispatcher());
    }

    public function testNewPairIdIsHexAndDistinct(): void
    {
        $a = Events::newPairId();
        $b = Events::newPairId();
        $this->assertSame(16, strlen($a));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function testEmitReturnsDispatcherReturnValueForStoppableSemantics(): void
    {
        // PSR-14 dispatchers return the (possibly mutated) event so
        // callers can chain. Our static helper preserves that.
        $stoppedEvent = new class implements FrameworkEvent, \Psr\EventDispatcher\StoppableEventInterface {
            public bool $stopped = false;
            public function isPropagationStopped(): bool { return $this->stopped; }
        };

        $provider = new ListenerProvider();
        $provider->listen(FrameworkEvent::class, static function (object $e): void {
            $e->stopped = true; // mutate
        });
        $provider->listen(FrameworkEvent::class, static function (): void {
            throw new \LogicException('listener after a stopped event must not run');
        });
        Events::useDispatcher(new EventDispatcher($provider));

        $returned = Events::emit($stoppedEvent);
        $this->assertSame($stoppedEvent, $returned);
        $this->assertTrue($stoppedEvent->stopped);
    }

    public function testEnabledReportsDispatcherPresence(): void
    {
        // Used by hot call sites (Pipeline, Router, Binder, App)
        // to skip pair-id minting + event construction entirely
        // when no dispatcher is installed. Without this gate the
        // no-subscriber case still pays for `random_bytes(8)` on
        // every middleware hop.
        $this->assertFalse(Events::enabled());

        Events::useDispatcher(new EventDispatcher(new ListenerProvider()));
        $this->assertTrue(Events::enabled());

        Events::useDispatcher(null);
        $this->assertFalse(Events::enabled());
    }

    public function testCurrentPairIdRoundTrips(): void
    {
        $this->assertNull(Events::currentPairId());

        Events::useCurrentPairId('abc123');
        $this->assertSame('abc123', Events::currentPairId());

        Events::useCurrentPairId(null);
        $this->assertNull(Events::currentPairId());
    }

    public function testCustomDispatcherImplementationIsAccepted(): void
    {
        // Apps wiring their own PSR-14 dispatcher (e.g. a Symfony
        // Messenger bridge) plug in via the same slot.
        $custom = new class implements EventDispatcherInterface {
            public array $seen = [];
            public function dispatch(object $event): object
            {
                $this->seen[] = $event;
                return $event;
            }
        };
        Events::useDispatcher($custom);

        $event = new class implements FrameworkEvent {};
        Events::emit($event);

        $this->assertSame([$event], $custom->seen);
    }
}
