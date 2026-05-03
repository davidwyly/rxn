<?php declare(strict_types=1);

namespace Rxn\Framework\Observability;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Static slot for the framework's observability dispatcher. Keeps
 * the call sites in `Pipeline`, `Router`, `Binder`, `App::serve()`
 * decoupled from any concrete dispatcher — they emit through
 * `Events::emit()`, which is a no-op until the app installs one.
 *
 * Same posture as `RequestId` / `BearerAuth` / `TraceContext` /
 * `DumpCache`: PHP's single-threaded request lifecycle scopes the
 * static slot. Apps that share a worker across requests (Swoole,
 * RoadRunner) install the dispatcher once at boot — listeners are
 * responsible for using event payload state (e.g. `$pairId`) to
 * separate concurrent requests rather than ambient slots.
 *
 *   $provider = new ListenerProvider();
 *   $provider->listen(FrameworkEvent::class, $myListener);
 *   Events::useDispatcher(new EventDispatcher($provider));
 *
 * Without an installed dispatcher, `Events::emit()` short-circuits
 * — the event-construction cost is paid by callers but the
 * dispatch cost is zero.
 */
final class Events
{
    private static ?EventDispatcherInterface $dispatcher = null;

    /**
     * Install the dispatcher. Pass `null` to remove (tests, or
     * apps disabling observability after boot).
     */
    public static function useDispatcher(?EventDispatcherInterface $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * Snapshot accessor — useful for tests and for code paths that
     * need to dispatch a non-framework event through the same
     * channel (e.g. an app-defined event that listeners are
     * watching for).
     */
    public static function dispatcher(): ?EventDispatcherInterface
    {
        return self::$dispatcher;
    }

    /**
     * Dispatch an event if a dispatcher is installed. No-op
     * otherwise. Returns the (possibly listener-mutated) event so
     * callers can still inspect it — useful in tests and for
     * stoppable-event semantics.
     */
    public static function emit(object $event): object
    {
        if (self::$dispatcher === null) {
            return $event;
        }
        return self::$dispatcher->dispatch($event);
    }

    /**
     * Mint a fresh pair id. Used by the framework to bracket
     * entered/exited events. 16 hex chars = 64 bits of entropy,
     * collision-free for any realistic interleaving within a
     * single worker.
     */
    public static function newPairId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
