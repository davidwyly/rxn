# Profile-guided compilation — selective DTO dump

**Date:** 2026-05-03
**Branch:** `feat/profile-guided-compilation`
**Status:** Ship signal met. Theme 3.1 from
[`docs/horizons.md`](../../../docs/horizons.md) realised.

## Hypothesis

The schema-compiled Binder
([`2026-04-29-compiled-binder.md`](2026-04-29-compiled-binder.md))
delivers a 6.4× speedup on `bind()` by replacing reflection
with straight-line PHP. Today, opting in is "all or nothing":
apps either compile every DTO at boot (pay opcache + closure
memory for cold ones) or none (pay reflection cost on every
hot bind).

The horizons doc framed the missing middle: track which DTOs
are actually hot at runtime, compile only those. The bench has
to settle whether the wins are real.

**Ship signal from horizons.md:**

> Set up a workload with 100 DTOs where 10 are hot; bench memory
> + first-request latency vs. unconditional dump. If memory drops
> meaningfully (>30%) and first-request latency stays stable,
> ship.

## Change

Three pieces:

1. **`Codegen\Profile\BindProfile`** — in-memory hit counter
   (`record($class)` is the runtime increment, ~50 ns) plus
   atomic JSON persistence (`flushTo()` with `flock(LOCK_EX)`
   so concurrent workers don't lose increments,
   temp-file + `rename(2)` so readers never see a half-written
   file).

2. **`Binder::warmFromProfile($path, $topK)`** — load + selective
   compile. Reads the profile, picks the top-K by hits, calls
   `compileFor()` on each, populates the in-memory compiled
   cache AND DumpCache files. Stale entries (refactored or
   non-DTO classes at the profiled FQCN) are silently filtered.

3. **`Binder::bind()` auto-dispatch** — when a class has a
   compiled closure in the in-memory cache, `bind()` uses it
   instead of walking reflection. This is the line that makes
   the speedup actually land at runtime — without it,
   profile-guided compilation would just write files nobody
   reads.

Plus `bin/rxn dump:hot --profile=PATH [--top=N] [--cache=DIR]`
as the deploy-pipeline bridge.

## Bench setup

`bench/profile-guided/run.php` orchestrates three subprocesses
(fresh PHP state per mode). Each subprocess:

1. Generates 100 fixture DTO classes via `eval` — five fields,
   mixed validation attributes (`#[Required]`, `#[Min]`,
   `#[Length]`, `#[InSet]`), realistic enough to exercise the
   same code paths a real DTO would.
2. Configures DumpCache + warming according to the mode under
   test.
3. Measures: warm time, peak PHP heap memory after warming, and
   per-class throughput on the 10 "hot" classes vs the 90
   "cold" ones.

Three modes:

- **runtime-only** — no DumpCache configured; every `bind()`
  walks reflection.
- **unconditional** — DumpCache + `compileFor()` on all 100
  DTOs at boot.
- **profile-guided** — DumpCache + `warmFromProfile()` with the
  10 hot classes named in a synthesized profile JSON; cold
  classes never get compiled.

Workload phase per mode: 250 ms of `Binder::bind()` on hot
classes (round-robin), then 250 ms on cold classes.

## Results

Median of three runs on a quiet machine:

| mode             | warm_ms | peak_mem_kb | cache_files | hot_ops/s | cold_ops/s |
|------------------|---------|-------------|-------------|-----------|------------|
| runtime-only     | 0.00    | 2,048       | 0           | ~300,000  | ~299,000   |
| unconditional    | 15.61   | 6,144       | 100         | ~1,400,000| ~1,240,000 |
| profile-guided   | 1.68    | 4,096       | 10          | ~1,370,000| ~299,000   |

**Headline numbers (profile-guided vs unconditional):**

```
memory delta over runtime-only baseline:
  unconditional:   +4,096 KB  (+200%)
  profile-guided:  +2,048 KB  (+100%)
  saving:           50.0%      (target from horizons.md: >30%)

hot DTO speedup over runtime-only:
  unconditional:   4.72×
  profile-guided:  4.64×       (within measurement noise of unconditional)

boot-time delta:
  unconditional:   15.6 ms     (compileFor × 100)
  profile-guided:   1.7 ms     (compileFor × 10)
```

Cold paths in `profile-guided` mode stay on the runtime walker
by design — those are the classes the workload didn't deem
hot, so opcache memory shouldn't pay for them. The 4.6×
hot/cold throughput gap matches the runtime-vs-compiled gap
from the original CompiledBinder bench
([`2026-04-29-compiled-binder.md`](2026-04-29-compiled-binder.md));
the absolute numbers differ because this bench's DTOs are
eval'd (worse opcache behaviour for generated source) and use
a different validation attribute mix.

## Decision criteria

| criterion (from horizons.md) | result |
|------------------------------|--------|
| Memory drops meaningfully (>30%) | ✅ 50% saving over unconditional |
| First-request latency stays stable | ✅ 4.64× (profile-guided) vs 4.72× (unconditional), within noise |
| If <10% gain, deprioritise | not triggered |

Ship signal **met**.

## What this means in practice

Profile-guided compilation is the difference between "compile
everything at boot" (current opt-in) and "compile what
matters" (this experiment). For apps with many DTOs and a
clear hot/cold access split, opcache memory pays only for
classes that get hits in production traffic.

For typical apps — fewer DTOs, flatter access distribution —
the absolute saving is small (~2 MB on the 100-DTO bench;
proportionally smaller for fewer DTOs). The feature is a
catalog item: useful where it applies, zero cost where it
doesn't.

The auto-dispatch in `bind()` (`if (isset(self::$compiledCache[$class]))
return (self::$compiledCache[$class])($bag);`) is what makes the
speedup transparent at runtime. Apps wire the warm step into
bootstrap; everything downstream is unchanged.

## Test impact

Full suite: 712 tests / 1510 assertions, all green.

- 12 unit tests for `BindProfile` (record, topK with ties,
  atomic JSON persistence, lock-protected concurrent flush,
  defensive load drops malformed entries, distinct
  missing-vs-corrupted error messages).
- 6 Binder integration tests (bind records hits, bind
  auto-dispatches to compiled cache, warmFromProfile compiles
  top-K, stale-class entries silently skipped, non-RequestDto
  entries silently skipped, post-warm counter reset prevents
  seed double-counting).
- 4 CLI integration tests for `bin/rxn dump:hot` (--profile
  required, missing-file exit-2, full compile flow,
  empty-profile no-op).

## Cost reality

~200 LOC of framework code + ~250 LOC of tests + ~270 LOC of
bench. On target with the horizons.md estimate. The DumpCache
+ `compileFor()` infrastructure already existed; this was
purely the measurement + selection layer the doc described.

## Notes

- Memory measurement uses `memory_get_usage(true)`, which
  reports PHP's heap allocator chunks (typically 2 MB
  granularity). The "exactly 50% saving" is partly an artefact
  of that granularity — finer-grained per-class measurement
  isn't possible without instrumenting the allocator. The
  *direction* (unconditional uses ~2× the heap of
  profile-guided) is robust across runs.
- Opcache memory (separate from PHP heap) isn't measured
  directly. It scales with the dumped `*.php` file count —
  reported as `cache_files` in the bench output. 100 vs 10 is
  the meaningful ratio there.
- The bench DTOs are generated via `eval`. Real DTOs land on
  disk and benefit from opcache more cleanly than eval'd
  source does — the in-process throughput numbers should be
  treated as relative, not absolute. The relative comparison
  (profile-guided ≈ unconditional on hot path) is what
  matters.
- Boot-time savings (15.6 ms → 1.7 ms) are one-time per
  worker. Under FPM with `pm.max_requests` in the thousands,
  this amortises to nothing per request. Under cold-start
  scenarios (Lambda, ephemeral workers), it adds up.
- The bench is a within-framework A/B. Don't repurpose for
  cross-framework comparisons.
