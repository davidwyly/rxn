<?php declare(strict_types=1);

namespace Rxn\Framework\Observability;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Static slot for the framework's observability dispatcher. Keeps
 * the call sites in `Pipeline`, `Router`, `Binder`, `App::serve()`
 * decoupled from any concrete dispatcher — every emit site reads
 * `Events::enabled()` and skips event allocation entirely when no
 * dispatcher is installed.
 *
 * Same posture as `RequestId` / `BearerAuth` / `TraceContext` /
 * `DumpCache`: a process-wide static slot scoped to PHP's standard
 * synchronous request lifecycle (one request per worker at a time).
 *
 * **Sync-only.** Under runtimes that interleave requests within a
 * single PHP process (Swoole / RoadRunner / OpenSwoole / Fiber-based
 * concurrency), the `currentPairId()` slot can be overwritten while
 * a request's Router/Binder calls are still in flight, attributing
 * the event to the wrong pair id. The dispatcher slot itself is
 * fine to share — it doesn't change per-request — but the per-
 * request pair id is not safe across coroutine boundaries. A future
 * fiber-aware variant would thread the pair id explicitly through
 * the call sites or store it on the PSR-7 request attribute bag.
 *
 *   $provider = new ListenerProvider();
 *   $provider->listen(FrameworkEvent::class, $myListener);
 *   Events::useDispatcher(new EventDispatcher($provider));
 *
 * Recommended pattern at the call site:
 *
 *   if (Events::enabled()) {
 *       Events::emit(new MyEvent(...));   // construct only when needed
 *   }
 *
 * Without an installed dispatcher, `Events::emit()` is itself a
 * safe no-op — but the gate above also avoids constructing the
 * event object in the first place, which matters on hot paths
 * (per-middleware, per-bind) where an unnecessary value-object
 * allocation adds up.
 */
final class Events
{
    private static ?EventDispatcherInterface $dispatcher = null;

    /**
     * Per-request pair id, set by `App::serve()` between
     * `RequestReceived` and `ResponseEmitted` and cleared in a
     * `finally` block when the response is written. Read by emit
     * sites that don't carry the id directly (Router, Binder).
     *
     * Sync-only — see the class docblock. Under coroutine-based
     * runtimes (Swoole, fibers) two requests can be in flight in
     * the same process and the slot doesn't separate them. PHP's
     * standard SAPI serialises requests so the slot is safe there.
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
