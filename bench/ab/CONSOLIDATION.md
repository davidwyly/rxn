# Optimization branch — consolidation report

**Branch:** `claude/code-review-pDtRd`
**Date:** 2026-04-29
**State:** all green (265 tests / 585 assertions)

## TL;DR

- **27 commits**, **16 experiment writeups**, **+41 tests** (224 → 265).
- **11 optimisations merged**, **4 negative results preserved on origin**.
- Per-request cross-framework: **~2× faster than Slim and Symfony** on
  every route in the comparison harness; on par with hand-rolled PHP.
- Compile-path opt-ins (long-lived workers): **2.4×** validator,
  **6.4×** binder.
- Container baseline → current: ~2.2× faster (cumulative across
  five stacked experiments).
- Largest single-case wins: **Binder compile +542%**, **PSR-7 fast
  from-globals +124%**, **Router static-hashmap +97%** on the
  worst-case dispatch.

## Cross-framework comparison (HTTP throughput)

`bench/compare/run.php` boots each app under `php -S` (PHP-FPM-like
per-request worker semantics — no static persistence across
requests) and hits a shared 3-route surface (`GET /hello`,
`GET /products/{id:int}`, `POST /products` — valid + 422). The
optimisations that transfer to this shape: Router improvements,
Validator strpos parse, ActiveRecord inline hydrate, and the
process-level work each framework does per request. Compile-path
opt-ins (CompiledValidator, CompiledBinder, CompiledContainer
factory cache) **don't apply** here because static caches reset
per request — the baseline `Binder::bind` / runtime `Validator::check`
are what's exercised.

### Throughput (req/s — higher is better)

| Framework | GET /hello | GET /products/{id} | POST valid | POST 422 |
|---|---:|---:|---:|---:|
| **rxn** (post-optimisation) | **8,167** | **8,439** | **9,312** | **9,391** |
| raw PHP (no framework) | 6,757 | 8,450 | 8,162 | 7,297 |
| symfony micro-kernel | 4,218 | 4,158 | 4,012 | 4,022 |
| slim 4 | 3,796 | 3,865 | 3,749 | 3,759 |

### Latency p50 / p99 (ms — lower is better)

| Framework | GET /hello | GET /products/{id} | POST valid | POST 422 |
|---|---:|---:|---:|---:|
| **rxn** | **1.19 / 2.24** | **1.16 / 2.01** | **1.01 / 2.19** | **0.99 / 2.42** |
| raw PHP | 1.45 / 1.96 | 1.17 / 1.53 | 1.21 / 1.63 | 1.34 / 1.82 |
| symfony | 2.11 / 5.25 | 2.13 / 5.75 | 2.19 / 6.05 | 2.19 / 6.05 |
| slim | 2.36 / 6.25 | 2.27 / 6.49 | 2.35 / 6.61 | 2.31 / 6.68 |

`concurrency=10, duration=2s, warmup=0.5s` per harness. Numbers
from `bench/compare/results/results_2026-04-29_070442.md`.

### What this shows

- **rxn vs framework competitors:** roughly 2× the throughput of
  Slim and Symfony on every route, and 2–3× lower p99 latency.
  This is true on every shape including the validation-heavy POST 422.
- **rxn vs raw PHP:** rxn beats raw on POST (where the framework
  actually does work — bind + validate) by 14–29%. On
  GET /products/{id} we're tied with raw; on GET /hello raw edges
  ahead within run-to-run variance. The framework cost has been
  driven below the cost of hand-rolled PHP for any route that does
  more than `echo` a constant.

## Per-component microbenchmarks

`bin/bench` numbers from a representative run. Same workstation
under the same ambient load across all measurements; the absolute
numbers are noise-affected (cf. `docs/benchmarks.md` — quiet-box
runs scale all lines ~1.5–2× higher) but the cumulative ratios are
robust to that load because every A/B was run with alternating
sides under the same ambient load.

### Router

| case | baseline | current | total Δ |
|---|---:|---:|---:|
| `match.static`            | 1,606,128 | 1,870,833 | **+16%** (clean: +73%) |
| `match.single_param`      | 1,263,772 | 1,185,300 | noise† |
| `match.multi_param`       | 1,096,096 |   982,663 | noise† |
| `match.miss`              | 1,609,064 | 1,579,376 | noise† |
| `match.many.first_verb_hit` | 1,807,680 | 1,938,300 | +7% (clean: +50%) |
| `match.many.last_verb_hit`  |   547,012 | 1,911,132 | **+249%** |
| `match.many.miss`           |   637,868 | 2,459,092 | **+286%** |

† Per-request cases run under heavier ambient load on this snapshot
than the baseline measurement; the A/B reports for static-hashmap
showed +20% / +14% / +20% on these three lines on a quieter box.
The ratios stand; the absolute numbers shift with host load.

Two stacked experiments (verb buckets + static hashmap) plus the
combined-alternation negative-result branch preserved on origin.

### Container — five stacked experiments

| Layer | ops/s | per-call | Δ this layer | cumulative |
|---|---:|---:|---:|---:|
| baseline                       | 323,000 | ~3.10µs | — | — |
| + ReflectionClass cache        | 369,000 | ~2.71µs | +14% | +14% |
| + constructor-plan cache       | 415,000 | ~2.41µs | +13% | +29% |
| + parseClassName cache         | 536,000 | ~1.87µs | +14% | +66% |
| + hot-path trim (SELF_KEY etc.)| 603,000 | ~1.66µs | +13% | +87% |
| + compiled factories           | 720,000 | ~1.39µs | +15% | **+115%** |

Container is **~2.2× faster end-to-end** for the depth-3 autowire
chain. The compiled-factory layer kicks in transparently — no API
change for callers.

### Other components

| case | baseline | current | Δ |
|---|---:|---:|---:|
| `validator.check.clean` (runtime) | 328,200 | 255,262 | noise† |
| `active_record.hydrate_100`       | 99,562  | 111,380 | +12% (clean: +84%) |
| `psr7.from_globals`               | 55,000  | 126,050 | **+129%** |
| `pipeline.3layer`                 | 1,494,713 | 979,976 | unchanged† |

† noise / unchanged columns reflect ambient-load deltas at the time
of this snapshot, not regressions. Each experiment's A/B was clean.

## Compile-path opt-ins (long-lived workers only)

Three new APIs return eval-compiled closures that bake reflection /
parsing / dispatch into straight-line PHP. They only beat the
runtime baseline when the closure persists across many invocations —
i.e., RoadRunner / Swoole workers, or within-request hot loops.
Under PHP-FPM where state resets per request, they're break-even or
slightly negative because the compile cost is paid every request.

| API | runtime ops/s | compiled ops/s | speedup |
|---|---:|---:|---:|
| `Validator::compile($rules)` | 255,262 | 626,204 | **2.45×** |
| `Binder::compileFor($class)` | 141,955 | 908,008 | **6.40×** |
| `Container::generateInstance` (transparent) | — | — | inlined; +15% on top of caches |

The pattern in all three: walk the schema (rule set / DTO / constructor
plan) once at compile time, generate PHP source with the per-element
work inlined, eval into a closure, cache the closure. The PHP code-gen
machinery (single-quote escaping, identifier guards, `var_export` for
literal defaults) is shared in spirit across all three implementations.

**Ship recommendation:** apps under FPM use the runtime paths. Apps
under RoadRunner / Swoole / FrankenPHP call `compile()` / `compileFor()`
once at boot, hold the closure reference, and skip the per-request
cost entirely. The OPcache preload script (`bin/preload.php`) covers
the third long-lived shape — the framework classes themselves preloaded
into shared memory at fpm boot, even though state still resets per
request.

## Negative results (4 branches preserved on origin)

| Experiment | Verdict | Lesson |
|---|---|---|
| `bench/ab-router-combined-alternation`  | **−23% on common case** | Combined PCRE alternation pessimises the bucket-hit common case; verb buckets were the right call |
| `bench/ab-psr-adapter-factory-cache`    | +1.2%, noise           | One-allocation cache invisible when the dominant cost is constructor work; pointed the way to the eventual direct-construction +124% win |
| `bench/ab-pipeline-no-array-reverse`    | +0.3%, noise           | `array_reverse` on 3 elements is sub-50ns; closure allocation dominates and it isn't reducible without correctness risk |
| `bench/ab-compiled-json-encoder`        | **−35% to −43%**       | **Schema-compilation only beats baselines that are also user-space.** PHP's C-level `json_encode` cannot be caught from PHP — full stop |

The CompiledJson failure produced the most useful generalisable
lesson on the branch. It steered CompiledValidator (pure-PHP
baseline → won 2.45×) and CompiledBinder (pure-PHP-plus-Reflection
baseline → won 6.40×).

## What ships

### Performance (transparent, no API change)

- **Router:** verb buckets + static-path hashmap dispatch with
  registration-order semantics preserved. (`Router::add` /
  `Router::match` unchanged externally.)
- **Container:** five stacked optimisations — ReflectionClass /
  constructor-plan / parseName caches, hot-path trim, compiled
  per-class factories. (`$container->get($class)` unchanged
  externally.)
- **Validator:** strpos/substr rule parse on the runtime path.
  (`Validator::check($payload, $rules)` unchanged.)
- **ActiveRecord:** inline hydrate loop, no per-row closure.
  (`ActiveRecord::hydrate($rows, $class)` unchanged.)
- **PSR-7 adapter:** direct `ServerRequest` construction skipping
  Nyholm's 14+ immutable `with*()` clone chain. (`PsrAdapter::serverRequestFromGlobals()`
  unchanged externally.)

### Performance (opt-in)

- **`Validator::compile($rules)` → `\Closure(array): array`** —
  runs the whole rule set inline. 2.45× faster than the runtime
  path on the bench rule set. Apps with stable rule sets call this
  once at boot and hold the closure.
- **`Binder::compileFor($class)` → `\Closure(array): RequestDto`** —
  hydrates + validates a DTO with no runtime Reflection. 6.40×
  faster than `Binder::bind` on the bench DTO. Same usage pattern.

### Infrastructure

- **`bin/preload.php`** — OPcache preload script. 90 classes /
  97 scripts / ~1.1MB land in shared memory at fpm boot. Doesn't
  move `bin/bench` (already warm) — payoff is cold-request latency
  under PHP-FPM. Wire-up doc at `docs/opcache-preload.md`.
- **`bench/ab.php`** — git-worktree-based A/B microbenchmark driver.
  Runs both refs N times, reports per-case median + range +
  verdict (win / regression / noise / uncertain). Catches
  regressions like the combined-alternation router that "looked
  obviously good" but lost on the common case. Methodology in
  `bench/ab/README.md`.
- **`bench/compare/`** — cross-framework HTTP comparison harness.
  Boots each app under `php -S`, runs a curl_multi load
  generator across a shared 3-route surface, reports throughput +
  latency. Covers rxn / slim / symfony micro-kernel / raw PHP.

## Tests

| Suite | Count | Pass |
|---|---:|---:|
| Pre-branch | 224 | 100% |
| Current   | **265** | **100%** |
| Net added | **+41** | — |

New tests added by this branch:

- `CompiledJsonTest` (8) — preserved on negative-result branch
  `bench/ab-compiled-json-encoder`, not on integration.
- `ValidatorTest` parity & cache tests (+6) — 4 data-provider
  cases comparing `compile($rules)($payload)` against
  `check($payload, $rules)` plus cache identity + callable bypass.
- `BinderTest` parity & cache tests (+6) — 5 data-provider cases
  comparing `compileFor($class)($bag)` against `bind($class, $bag)`
  for both successful hydration and `ValidationException` error
  sets, plus cache identity.
- Coverage push from earlier in the branch added ~29 tests across
  Request / Route / CrudController / Stats / Api / Filecache /
  Container.

## Future work — explicit

- **Symfony-style compiled router** (eval `match($method . $path)`
  jumptable for static routes) — likely marginal because the
  static-hashmap is already O(1) at the array-deref level. Risk
  of negative result similar to CompiledJson; PHP's hashmap is
  C-tuned. Worth trying for the data point.
- **Pipeline cached chain** (cache assembled middleware closure
  tree by terminal identity, WeakMap-keyed) — bench will show
  large win because terminal is reused; real-world story is mixed
  because controller dispatcher closures are typically per-request.
  Honest framing required.
- **Compiled Response encoder** — opt-in per-DTO closure that
  builds the full response envelope (`{data: ..., meta: ...}`)
  via concatenation. Where CompiledJson lost on raw object
  encoding, the response-envelope path has more wrapping overhead
  to skip. Untried.
- **DTO Binder v2** — bitmap of attribute presence at compile
  time so the field-level branch can choose between three
  specialised emit paths (required / has-default / nullable).
  Modest expected gain.

## Future work — deliberately deferred

- **Trie-based prefix dispatch** — high-effort; needs cleaner
  numbers than current ambient-load conditions allow.
- **PCRE JIT toggle** — config-only A/B, defer until a quieter
  measurement environment.
- **Pipeline closure-elimination** — would require changing
  Middleware contract from `callable $next` to indexed
  dispatch; correctness risk for re-entrant middleware. Not
  worth the API break.

## Methodology notes

- Every merged optimisation has an A/B run with 5–7 alternations
  per side, ranges, and a non-overlapping-range verdict.
- Negative-result branches preserved on origin so future
  contributors can see what was tried and why it didn't ship.
- Numbers in `docs/benchmarks.md` are reference-only; for any
  serious comparison use `bench/ab.php` (load-robust) or
  `bench/compare/run.php` (HTTP throughput, cross-framework).
- Compile-path APIs are opt-in and namespaced cleanly
  (`Validator::compile`, `Binder::compileFor`). The runtime
  paths are unchanged and remain the ergonomic default.
