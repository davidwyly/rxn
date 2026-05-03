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
     * Per-request pair id, set by `App::serve()` between
     * `RequestReceived` and `ResponseEmitted`. Read by emit
     * sites that don't carry the id directly (Router, Binder)
     * so listeners on concurrent-worker setups can attribute
     * the event to a specific in-flight request.
     */
    private static ?string $currentPairId = null;

    /**
     * Install the dispatcher. Pass `null` to remove (tests, or
     * apps disabling observability after boot).
     */
    public static function useDispatcher(?EventDispatcherInterface $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * True when a dispatcher is installed. Hot call sites read
     * this BEFORE constructing event objects or calling
     * `newPairId()` — when no dispatcher is installed, both costs
     * are skipped entirely so apps that don't subscribe pay only
     * one method call per emit point.
     */
    public static function enabled(): bool
    {
        return self::$dispatcher !== null;
    }

    /**
     * Set the request-scoped pair id. Cleared (passed `null`)
     * after the response is written, in a finally block, so a
     * stale id doesn't leak across requests in long-running
     * workers (Swoole / RoadRunner).
     */
    public static function useCurrentPairId(?string $pairId): void
    {
        self::$currentPairId = $pairId;
    }

    /**
     * Read the request-scoped pair id, or null when none is set
     * (CLI / non-`App::serve()` entry points, or after the
     * response has been written).
     */
    public static function currentPairId(): ?string
    {
        return self::$currentPairId;
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
