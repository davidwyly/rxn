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
 * @template T
 * @param array<int|string, Promise<T>> $promises
 * @return T
 */
function awaitAny(array $promises): mixed
{
    $lastError = null;
    foreach ($promises as $promise) {
        try {
            return $promise->wait();
        } catch (\Throwable $e) {
            $lastError = $e;
        }
    }
    throw $lastError ?? new \RuntimeException('awaitAny called with no promises');
}
