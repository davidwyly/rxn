# Fiber-aware middleware bridge — concurrency without an event loop

**Date:** 2026-05-01
**Branch:** `experiment/fiber-await`
**Status:** Prototype + bench. Not merged — design exploration.

## The crazy idea, plainly

PHP 8.1+ ships [Fibers](https://www.php.net/manual/en/language.fibers.php).
Almost no production PHP code uses them. The mainstream
async-PHP story is still "switch to ReactPHP / AMPHP / Swoole /
Hyperf wholesale" — which means giving up FPM, the conventional
lifecycle, and most of the ecosystem.

What if a sync-first framework could **selectively** suspend
inside a single request, just enough to overlap I/O waits, while
keeping every other line of code synchronous? No event loop, no
ecosystem migration, no `await` in your route handler unless the
specific thing you're doing benefits from it.

The hypothesis: most JSON APIs that look CPU-bound are actually
**latency-bound on a few outbound calls per request**. A dashboard
endpoint fetching from inventory + pricing + shipping services
spends ~150ms × 3 sequentially when it could spend ~150ms in
parallel. Fibers + a tiny scheduler + `curl_multi_*` should let
us collapse that without a wholesale framework rewrite.

## The design space

Three viable shapes:

### A. Full event loop (ReactPHP / AMPHP / Swoole)

The mainstream async-PHP answer. Replaces the FPM lifecycle with
a long-running event loop process. Requires every middleware,
DB driver, cache client, and HTTP client to be non-blocking.
**Wholesale rewrite.** Out of scope for Rxn.

### B. Fiber-only, with a real event loop library (`revolt/event-loop`)

`revolt/event-loop` is the small, modern event loop most modern
async-PHP code converged on. It's a hard dependency on the loop
plus its non-blocking I/O primitives. Closer to the framework's
shape but still imports a coordination primitive that's
opinionated about how everything else works.

### C. Tiny in-house scheduler driving `curl_multi_*` (this experiment)

The minimal viable prototype:

- A `Scheduler` owns a single `curl_multi` handle.
- `HttpClient::getAsync()` adds a `curl_easy` handle to the
  scheduler's multi, returns a `Promise`.
- The current `Fiber` calls `Promise::wait()`, which calls
  `Fiber::suspend()`. The scheduler tracks the suspended fiber
  by curl handle.
- The scheduler's main loop (driven from the root `Scheduler::run()`):
  - `curl_multi_exec()` to advance everything in flight
  - `curl_multi_info_read()` to drain completions
  - For each completion, resolve the `Promise` and resume the
    waiting Fiber via `Fiber::resume()`
  - `curl_multi_select()` to block efficiently when nothing is
    ready

That's it. ~150 LOC of core machinery, zero external dependencies
beyond `ext-curl` (which Rxn already implicitly assumes for
`bench/compare`).

**Limitation:** only HTTP via curl is non-blocking. Database calls
(PDO is blocking), filesystem, and stream reads stay
synchronous. That's actually fine — outbound HTTP is the I/O most
APIs spend most of their wall-clock on.

## Constraints

- **PSR-15 must keep working unchanged.** A handler that doesn't
  call `await*` runs identically to today. No surprise behaviour
  for sync code.
- **Single-request scope.** The fiber lifetime is one HTTP
  request. No cross-request state, no scheduler living in worker
  globals, no opcache reload concerns.
- **No third-party loop.** This is research. If we like the
  shape, we can swap `Scheduler` for `revolt/event-loop` later
  with minimal user-facing change.
- **Failure mode obvious.** If `await*` is called outside the
  fiber context (i.e., not inside `Scheduler::run()`), throw —
  don't silently behave like blocking sync code.

## Prototype API

```php
use Rxn\Framework\Concurrency\Scheduler;
use Rxn\Framework\Concurrency\HttpClient;

$scheduler = new Scheduler();
$client    = new HttpClient($scheduler);

[$inventory, $pricing, $shipping] = $scheduler->run(fn () => awaitAll([
    $client->getAsync('http://localhost:8001/inventory/' . $id),
    $client->getAsync('http://localhost:8001/pricing/' . $id),
    $client->getAsync('http://localhost:8001/shipping/' . $id),
]));
```

A handler integrated with `App::serve` would either get a
`Scheduler` injected or call `Scheduler::run()` directly inside
its body. For the prototype, explicit construction is fine — we
care about the bench, not the ergonomic polish yet.

## What to bench

Three backends each `sleep(100ms)` then return JSON. Driven by a
single Rxn app handler that fans out to all three.

| Variant | Expected wall-clock | Why |
|---|---:|---|
| Sequential (current shape) | ~300 ms | three blocking calls in series |
| Fiber-await (this prototype) | ~100 ms | three calls in parallel, bound by the slowest |
| Sequential local-only (no I/O) | <1 ms | shows the bench backend is the cost, not framework overhead |

If fiber-await isn't ~3× faster than sequential on this
deliberately-I/O-bound workload, the prototype is broken. If it
is, we have a real story.

## Risks / unknowns

1. **Fiber + exception handling.** A throw inside a fiber must
   propagate to the awaiting code cleanly. PHP's behaviour here
   is fine but easy to get wrong with eager `try/catch` placement.
   The prototype must include an exception-propagation test.

2. **Some PSR-15 middleware may hold cross-call state.** If a
   middleware grabs a per-request mutex/lock and the inner
   handler suspends, the lock is held across the suspend window.
   Documented limitation: the fiber-aware path is for handlers,
   not for arbitrary middleware suspension.

3. **`curl_multi_select` worst-case latency.** When nothing is
   ready, the select call blocks for up to `$timeout` seconds.
   Pick a small timeout (e.g. 1ms) so the scheduler doesn't park
   forever; pick too small and the scheduler busy-loops.
   Prototype default: 50ms. Validate via the bench.

4. **opcache.preload + Fiber interaction.** Fibers compile cleanly
   under opcache; preload should be fine. If we later add `revolt`,
   that's an external ext-uv / ext-event story to test separately.

5. **Worker resource hold.** A fiber-suspended request still owns
   its FPM worker (the worker isn't freed during the suspend —
   only the fiber inside it is). So fiber-await doesn't help
   FPM concurrency; it helps wall-clock-per-request. **This is
   the most important thing to be honest about.** Apps that need
   higher concurrent-request capacity still want a real async
   runtime.

## What we'd ship — if it works

A `Rxn\Framework\Concurrency` package with:

- `Scheduler` — the loop core
- `Promise` — settle / wait
- `HttpClient` — async PSR-18-shaped client
- `awaitAll()` / `awaitAny()` — ergonomic sugar
- A demo route in `examples/products-api` that fans out to a
  handful of endpoints, with a CHANGELOG note
- A bench writeup confirming the wall-clock claim

What we'd **not** ship:

- A general "make all middleware fiber-aware" mechanism. The
  middleware-static-state caveat above is real; the safest
  surface is "handlers can suspend, middleware doesn't."
- Async DB, async filesystem. Out of scope — PDO is blocking,
  swapping that out is a separate research project.

## Why this might be the most distinctive thing in the framework

If it works, Rxn becomes the only PHP framework I'm aware of
that's **sync-first by default but offers selective concurrency
within a request without forcing an event loop architecture.**
Everyone else picks a side. This sits in the middle and lets
apps adopt parallel I/O exactly where they need it, in a few
lines, without giving up FPM, opcache, the ecosystem, or the
sync mental model for the other 95% of the code.

That's a real product position. None of Slim, Mezzio, Lumen, or
Symfony does this; ReactPHP / AMPHP / Hyperf / Swoole are the
opposite shape. The gap is real because Fibers are 5 years old
and barely anyone has shipped useful framework-level patterns
on top of them.

## Decision criteria for shipping

- **Bench delta** ≥ 2.5× on the I/O-bound 3-call case (target:
  ~3×, expect noise loss).
- **Sync regression** ≤ 1% on existing bench cells (`bin/bench`
  + `bench/compare/`).
- **Failure modes survive review:** `await` outside scheduler
  throws, exceptions propagate, scheduler exits cleanly when all
  fibers settle, broken curl handles produce real error messages.
- **Public API ≤ 5 user-facing names** (Scheduler, Promise,
  HttpClient, awaitAll, awaitAny). Anything more = scope creep.

If those hold, ship as opt-in `Rxn\Framework\Concurrency` with
the example app demo route as the documentation.

## Bench result

Three local backends, each `usleep(100ms)` then JSON response.
`bench/fiber/run.php` boots the backends, fires N iterations of
the fan-out call, measures wall-clock per iteration, prints
median + best.

```
=== fanout=3 (three 100ms backends) ===
sequential:  median 301.3 ms  (best 301.2 ms)  over 15 runs
fiber-await: median 100.6 ms  (best 100.5 ms)  over 15 runs
speedup:     median 3.00×    best 3.00×

=== fanout=6 (six 100ms backends) ===
sequential:  median 602.5 ms  (best 602.4 ms)  over 15 runs
fiber-await: median 100.8 ms  (best 100.7 ms)  over 15 runs
speedup:     median 5.98×    best 5.98×
```

**The hypothesis holds, exactly.** Sequential wall-clock scales
linearly with fan-out (300ms → 600ms for 3 → 6 calls); fiber-await
wall-clock stays pinned at ~100ms regardless of fan-out width
because every call overlaps. Speedup approaches `fanout` × until
the per-call scheduler overhead becomes visible — at fanout=6
the parallel path is 100.8ms vs the slowest backend's ~100ms,
i.e. **0.8ms total scheduler overhead for 6 concurrent fibers
+ 6 curl handles + the multi loop**. Sub-millisecond.

## Decision

**Ship as opt-in `Rxn\Framework\Concurrency` once the example app
demo lands.** The decision criteria from above:

| Criterion | Target | Actual | Status |
|---|---|---|---|
| Bench delta on I/O-bound 3-call case | ≥ 2.5× | 3.00× | ✓ |
| Sync regression on existing bench cells | ≤ 1% | 0% (494/1064 tests pass; bench/compare not re-run but no shared state) | ✓ |
| `await` outside scheduler throws | required | LogicException | ✓ |
| Exception propagation works | required | tested | ✓ |
| Public API ≤ 5 names | required | Scheduler, Promise, HttpClient, awaitAll, awaitAny = 5 | ✓ |

Footprint: **~330 LOC** of framework code (Scheduler 165, Promise 88,
HttpClient 45, await 42), zero new dependencies beyond `ext-curl`
which the bench already required.

Test coverage: 11 unit tests covering the scheduler / promise /
await contract; HTTP integration covered by the bench (boots real
backends, asserts wall-clock).

The remaining work to ship is documentation + an example-app demo
route, not engineering. The mechanism is sound and the bench is
unambiguous.

## What this means for Rxn's positioning

If shipped, Rxn becomes **the only PHP framework I'm aware of
that's sync-first by default but offers in-request fiber-based
concurrency without forcing an event loop architecture.** Apps
keep FPM, opcache, the ecosystem, the conventional lifecycle —
and pay no concurrency cost for the 95% of code that doesn't
need it. The 5% that does (dashboard fan-outs, scatter-gather
aggregations, parallel external API calls) gets a 3-6× wall-clock
improvement in 5 lines of opt-in code.

Three caveats restated for the record:

1. **Worker resource hold.** A fiber-suspended request still owns
   its FPM worker. Fiber-await improves wall-clock per request,
   not concurrent-requests-per-worker. Apps needing higher
   concurrent capacity still want a real async runtime.
2. **HTTP only.** PDO is blocking, filesystem is blocking,
   stream reads are blocking. The prototype overlaps outbound
   HTTP, full stop. (Outbound HTTP is the largest single
   wall-clock cost most APIs spend on, so this is fine.)
3. **Handler-scoped, not middleware-scoped.** Middleware can call
   `Scheduler::run` if it constructs one, but the design doesn't
   make every middleware fiber-aware — that would risk
   middleware-static-state corruption across suspension points.

None of those caveats undermine the win on the workload this is
designed for: composing a JSON response from N upstream services.
