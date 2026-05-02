<?php declare(strict_types=1);

namespace Rxn\Framework\Concurrency;

/**
 * Wait for every promise in `$promises` to settle, then return a
 * map of values keyed identically to the input. If any promise
 * rejects, the rejection propagates up to the caller — no partial
 * results.
 *
 * The implementation is deliberately the simplest thing that works:
 * sequence the waits. The scheduler loop drives every curl handle
 * in parallel anyway, so the wall-clock is already
 * `max(call_durations)` regardless of which promise we wait on
 * first. The list traversal just collects the settled values.
 *
 * @template T
 * @param array<int|string, Promise<T>> $promises
 * @return array<int|string, T>
 */
function awaitAll(array $promises): array
{
    $out = [];
    foreach ($promises as $key => $promise) {
        $out[$key] = $promise->wait();
    }
    return $out;
}

/**
 * Wait for the first promise in `$promises` to fulfil and return
 * its value, ignoring later results. If every promise rejects, the
 * last rejection is re-thrown.
 *
 * Implementation: register the current fiber as a waiter on every
 * pending promise so that whichever one settles first resumes us.
 * The scheduler's `isSuspended` guard in `tick()` ensures extra
 * resume enqueues (from promises that settle after the first) become
 * no-ops once the fiber is running again. We loop until a fulfilled
 * value is found or all promises have settled as rejections.
 *
 * @template T
 * @param array<int|string, Promise<T>> $promises
 * @return T
 */
function awaitAny(array $promises): mixed
{
    if (empty($promises)) {
        throw new \RuntimeException('awaitAny called with no promises');
    }

    $fiber = \Fiber::getCurrent();
    if ($fiber === null) {
        throw new \LogicException(
            'awaitAny() called outside a fiber. Wrap the call in Scheduler::run().',
        );
    }

    // $handled tracks promises we've already tried so we don't
    // re-throw the same rejection twice across loop iterations.
    $handled   = new \SplObjectStorage();
    $lastError = null;

    while (true) {
        // Check every promise: return on the first fulfilled one,
        // record rejections, collect what's still pending.
        $pending = [];
        foreach ($promises as $promise) {
            if ($promise->isPending()) {
                $pending[] = $promise;
                continue;
            }
            if ($handled->contains($promise)) {
                continue; // Already processed this rejection.
            }
            $handled->attach($promise);
            try {
                return $promise->wait(); // Fulfilled — return immediately.
            } catch (\Throwable $e) {
                $lastError = $e;         // Rejected — track, keep looking.
            }
        }

        if ($pending === []) {
            // All settled and all rejected (otherwise we'd have returned above).
            throw $lastError ?? new \RuntimeException('awaitAny: all promises rejected without a value');
        }

        // None fulfilled yet. Register on every still-pending promise
        // so we wake up as soon as any one of them settles.
        foreach ($pending as $promise) {
            $promise->notifyFiberOnSettle($fiber);
        }
        // Suspend until the scheduler resumes us (first settle wins).
        \Fiber::suspend();
    }
}
