<?php declare(strict_types=1);

namespace Rxn\Framework\Concurrency;

/**
 * Tiny in-house fiber scheduler driven by `curl_multi_*`. Owns one
 * `curl_multi` handle, tracks suspended fibers per `curl_easy`
 * handle, and resumes them as completions drain.
 *
 * Lifecycle:
 *
 *   $scheduler = new Scheduler();
 *   $result = $scheduler->run(function () use ($client) {
 *       return awaitAll([
 *           $client->getAsync($urlA),
 *           $client->getAsync($urlB),
 *       ]);
 *   });
 *
 * `run()` creates a root fiber, starts it, then ticks until the
 * root fiber returns. Inside the fiber, `await*` / `Promise::wait()`
 * suspend; `HttpClient::getAsync()` enqueues a curl handle that the
 * tick loop drives.
 *
 * Single-request scope. Construct one per `App::serve` invocation
 * (or directly in a handler). No worker-global state, no
 * cross-request concerns.
 */
final class Scheduler
{
    /** @var \CurlMultiHandle|null */
    private ?\CurlMultiHandle $multi = null;

    /**
     * curl_easy handle id → Promise to settle on completion.
     *
     * @var array<int, Promise<string>>
     */
    private array $promisesByHandle = [];

    /**
     * curl_easy handle id → handle (kept alive for the duration
     * of the request). Released after settle.
     *
     * @var array<int, \CurlHandle>
     */
    private array $handlesById = [];

    /** @var list<\Fiber> Fibers ready to resume on the next tick. */
    private array $resumeQueue = [];

    /** @var int Default curl_multi_select timeout in milliseconds. */
    private int $selectTimeoutMs = 50;

    public function __construct(?int $selectTimeoutMs = null)
    {
        if ($selectTimeoutMs !== null) {
            $this->selectTimeoutMs = $selectTimeoutMs;
        }
    }

    /**
     * Run `$body` inside a fiber, ticking the loop until the fiber
     * returns (or throws). Any number of nested `await*` calls and
     * `HttpClient::getAsync()` registrations work — they just
     * enqueue handles or fibers and the loop drains them.
     *
     * @template T
     * @param callable(): T $body
     * @return T
     */
    public function run(callable $body): mixed
    {
        $result = null;
        $error  = null;

        $root = new \Fiber(function () use ($body, &$result, &$error): void {
            try {
                $result = $body();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        $root->start();

        // Drain until the root finishes. While the root is suspended
        // (waiting on a promise), tick the curl loop and resume any
        // ready fibers.
        while (!$root->isTerminated()) {
            $this->tick();
        }

        if ($error !== null) {
            throw $error;
        }
        return $result;
    }

    /**
     * Register a curl_easy handle with the multi loop and return a
     * Promise that settles to the response body string. Called by
     * HttpClient — most user code should not touch this directly.
     *
     * @return Promise<string>
     */
    public function submitCurl(\CurlHandle $handle): Promise
    {
        $multi = $this->ensureMulti();
        $rc    = curl_multi_add_handle($multi, $handle);
        if ($rc !== CURLM_OK) {
            throw new \RuntimeException(
                "Scheduler: curl_multi_add_handle failed: " . curl_multi_strerror($rc),
            );
        }
        $promise = new Promise($this);
        $id      = spl_object_id($handle);
        $this->promisesByHandle[$id] = $promise;
        $this->handlesById[$id]      = $handle;
        return $promise;
    }

    /**
     * Queue a fiber for resumption on the next tick. Called by
     * Promise::resumeWaiters() when a producer settles.
     */
    public function enqueueResume(\Fiber $fiber): void
    {
        $this->resumeQueue[] = $fiber;
    }

    /**
     * Single tick:
     *  1. Resume any fibers queued from the previous tick (so a
     *     just-settled promise actually advances its waiter).
     *  2. Drive curl_multi until it has nothing immediate to do.
     *  3. Drain completions, settle promises (which queues more
     *     resumes for the *next* tick — that's fine, we'll loop).
     *  4. If still waiting on something, block in curl_multi_select
     *     up to selectTimeoutMs.
     */
    private function tick(): void
    {
        // (1) Resume what's already queued.
        $queue = $this->resumeQueue;
        $this->resumeQueue = [];
        foreach ($queue as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        // (2)+(3) Drive curl + drain completions, if there's a multi.
        if ($this->multi !== null) {
            do {
                $code = curl_multi_exec($this->multi, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($this->multi)) !== false) {
                /** @var \CurlHandle $handle */
                $handle = $info['handle'];
                $id     = spl_object_id($handle);
                $promise = $this->promisesByHandle[$id] ?? null;
                if ($promise === null) {
                    // Stale completion (e.g. handle was closed).
                    curl_multi_remove_handle($this->multi, $handle);
                    continue;
                }
                if ($info['result'] === CURLE_OK) {
                    $body = (string) curl_multi_getcontent($handle);
                    $promise->fulfill($body);
                } else {
                    $err = curl_strerror($info['result']) . ' (' . curl_error($handle) . ')';
                    $promise->reject(new \RuntimeException("curl: $err"));
                }
                curl_multi_remove_handle($this->multi, $handle);
                curl_close($handle);
                unset($this->promisesByHandle[$id], $this->handlesById[$id]);
            }

            // (4) Block until something is ready, or timeout.
            if ($running > 0 && $this->resumeQueue === []) {
                @curl_multi_select($this->multi, $this->selectTimeoutMs / 1000);
            }
        }

        // If we have nothing to do (no multi, no queue), the root
        // fiber must be advancing on its own — yielding it a tick
        // would risk a tight loop. Caller's `while (!terminated)`
        // will reach this branch only briefly during fiber returns.
    }

    private function ensureMulti(): \CurlMultiHandle
    {
        if ($this->multi === null) {
            $this->multi = curl_multi_init();
        }
        return $this->multi;
    }

    public function __destruct()
    {
        if ($this->multi !== null) {
            foreach ($this->handlesById as $h) {
                @curl_multi_remove_handle($this->multi, $h);
                @curl_close($h);
            }
            curl_multi_close($this->multi);
        }
    }
}
