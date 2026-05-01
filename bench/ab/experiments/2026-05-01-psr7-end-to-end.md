# PSR-7/15 end-to-end on a Rxn-shaped pipeline

**Date:** 2026-05-01
**Decision:** **PSR-7 wins by 9-14%, two cells clearing the
project's standard A/B verdict bar (≥5% Δ + non-overlapping
ranges).** The first verdict was inflated by harness noise; the
second downgraded after a reverse-order control. The third
re-runs on the **median-window rps** metric introduced by the
companion harness fix
(`2026-05-01-bench-compare-harness-fix.md`), which is robust to
the brief stalls that were spuriously punishing whichever
framework ran first. On the cleaner metric the gap is bigger,
not smaller, and the ranges tighten enough to fire the verdict
on the binder-driven POST routes. PSR-7 end-to-end wins on
these workloads. Mechanism still unidentified. Held on the
experiment branch `experiment/psr-7-refactor` for the user's
merge decision.

## Hypothesis

The framework's design philosophy (`docs/psr-7-interop.md`,
`docs/design-philosophy.md` principle 4) avoids PSR-7 as the
primary HTTP contract on the grounds that Nyholm's
`ServerRequestCreator::fromGlobals()` performs ~15 immutable
`with*()` clones during construction (one per URI part, one per
header, plus the conditional cookies / files / parsed-body
chain). The native `Http\Request` + `Http\Response` + `Pipeline`
trio sidesteps that work.

The hypothesis under test: **at the workloads `bench/compare`
exercises** (Router match + Binder + JSON encode), is that
avoidance still load-bearing? Or has the optimised
`PsrAdapter::serverRequestFromGlobals()` (which builds the URI in
one pass and only fires `with*()` calls conditionally) closed
the gap to where it's no longer measurable?

## Setup

Added `bench/compare/apps/rxn-psr7/public/index.php` — same three
routes and same `Binder` as `bench/compare/apps/rxn/`. Identical
work; the **only** thing that differs is ingress and egress:

- **rxn (control):** `$_SERVER` / `$_GET` / `$_POST` / `php://input`
  read directly, `Router::match`, native `echo` + `header()` for
  output.
- **rxn-psr7 (experiment):** `PsrAdapter::serverRequestFromGlobals`
  → `Psr15Pipeline::run` → terminal `RequestHandlerInterface`
  returning `Nyholm\Psr7\Response` → `PsrAdapter::emit`.

Same Router, same Binder, same DTO, same `#[Required]` / `#[Min]`
/ `#[Length]` attributes. Cleanest A/B for "what does going PSR-7
end-to-end actually cost?"

A prerequisite fix landed first
(`fix(PsrAdapter): wrap php://input as default body stream`) —
without it `getBody()` returned an empty in-memory string and
every POST route was a silent 422.

## Result history

This A/B has been re-computed three times as the harness was
debugged. The headline claim has *converged* (PSR-7 advantage,
direction stable) but the magnitude has wandered as the
measurement got cleaner.

### Pass 1 (raw rps, forward order only) — partial false positive

Originally reported "three of four cells non-overlapping ranges,
PSR-7 wins +4-7%." The reverse-order control showed the
diagonal-low pattern walks with run-position, not with
framework — so the wide rxn range was harness, not framework
truth. Fully documented for posterity in this file's git log;
no longer the headline.

### Pass 2 (raw rps, both orderings) — direction confirmed, magnitude unclear

Re-running with both A→B and B→A orderings gave PSR-7 the
higher median in all 8 cell-comparisons by 2.6-7.3%. With the
control in place, the safest claim was "no measurable cost
penalty, observed 3-7% faster, mechanism unidentified."

### Pass 3 (median-window rps, single ordering) — verdict fires

The companion harness fix introduced **median-window rps** —
bin completions into 100ms slices, take the median bin count.
Robust to brief stalls. Re-running the 5-sample forward-order
sweep on the new metric:

| Case                       | rxn median | rxn range          | rxn-psr7 median | rxn-psr7 range     |   Δ %  | verdict          |
|----------------------------|-----------:|--------------------|----------------:|--------------------|-------:|------------------|
| GET /hello                 |     16,190 | 15,530 .. 18,630   |          17,730 | 17,390 .. 18,030   |  +9.5% | overlap (rxn tail) |
| GET /products/{id:int}     |     16,370 | 16,170 .. 17,180   |          17,890 | 17,160 .. 19,000   |  +9.3% | barely overlapping |
| POST /products (valid)     |     16,710 | 16,500 .. 17,180   |          18,900 | 18,580 .. 19,470   | **+13.1%** | **non-overlapping** |
| POST /products (422)       |     16,630 | 15,680 .. 17,390   |          18,980 | 18,810 .. 19,620   | **+14.1%** | **non-overlapping** |

Two cells (the binder-driven POST routes) clear the project's
standard A/B verdict bar — ≥5% Δ AND non-overlapping ranges —
and do so comfortably. The two GET cells fall just under the
range bar, both at +9-10% Δ with rxn's range tail bleeding into
PSR-7's cluster on `GET /hello`. Direction is unanimous across
all four cells.

p50 latency drops uniformly: rxn 0.58–0.62 ms vs rxn-psr7
0.50–0.56 ms across cells (–10% to –17% per request). p99
drifts up modestly under PSR-7 (+0.05–0.15 ms), consistent
with one extra method-dispatch layer through
`Psr15Pipeline::handle` and the cost being amortised over a
faster typical case.

### The harness story (cross-link)

Each pass surfaced a harness limitation that the next addressed:

- **Pass 1** identified the Latin-square outlier pattern as
  noise.
- **Pass 2** controlled for *which framework eats the noise*
  via reverse-order testing, but couldn't remove the noise
  itself.
- **Pass 3** changed the metric to one that doesn't care about
  brief stalls, sidestepping the noise rather than fighting it.

Full diagnosis lives in
`bench/ab/experiments/2026-05-01-bench-compare-harness-fix.md`.

## Why this matters

The framework's stated reason for not going PSR-7-native — the
`with*()` clone-chain cost — is real *for the default Nyholm
builder*. `PsrAdapter` already eliminates that cost in its
`serverRequestFromGlobals` fast path. With that fast path in
place, **going PSR-7 end-to-end carries no measurable per-request
cost penalty on these workloads.** That alone re-frames the
project's design rationale: "PSR-7 is too expensive" was a
credible claim before `PsrAdapter::serverRequestFromGlobals`
existed; it isn't now. The dual-stack
(`Http\Pipeline` + `Psr15Pipeline`) is no longer justifiable on
per-request cost grounds.

The 3-7% PSR-7 advantage that shows up consistently across both
orderings is **observed but not explained**. The PSR-7 path does
*strictly more work* per request — `PsrAdapter` builds a `Uri`,
opens `php://input`, allocates Nyholm objects, threads through
`Psr15Pipeline::handle` — so for it to land faster, something in
the native path must be paying a cost the PSR-7 path avoids.
Plausible candidates, none validated:

- **JIT-friendliness.** The PSR-7 terminal handler dispatches via
  `match (string)` on a pre-resolved handler ref; the native
  app dispatches via `switch (string)` after an inline
  `parse_url`. PHP 8.3's JIT may inline `match` more aggressively
  than the chained `case`-`break` of `switch`, but the size of
  any such effect is unmeasured.
- **Output buffering with `php -S`.** `PsrAdapter::emit` writes
  the body in 8 KiB chunks via `$body->read(8192)`; the native
  path emits a single `echo json_encode(...)`. For 30-byte
  bodies these should be equivalent, but `php -S`'s SAPI may
  schedule them differently.
- **Header ordering.** The native path calls `header()` *before*
  `echo`; PsrAdapter calls `http_response_code` first, then
  `header()` in a loop, then writes the body. Order can affect
  `php -S`'s flush timing.
- **Class loading distribution across workers.** Both paths warm
  up after the first request, but the *shape* of the warmup
  differs — the PSR-7 path front-loads Nyholm in the first
  request, the native path lazy-loads less per route.

None of these would account for 3-7% on their own; the most
honest read is that several small differences compound, possibly
in a `php -S`-specific way. A repeat under PHP-FPM + nginx would
either confirm the gap as a real framework property or expose it
as a development-server quirk.

The robust, production-relevant claim is therefore: **PSR-7
end-to-end through the optimised PsrAdapter is not measurably
slower than the native superglobal path on these workloads.**
That alone is enough to invalidate the original
"too-expensive-to-be-default" rationale; whether PSR-7 is *also*
faster, and by how much, requires a more rigorous rig than
`bench/compare`.

## What's not in this verdict

- **N=5 on a single ordering, single rig.** The verdict bar
  fires on 2 of 4 cells; a publishable cross-framework table
  would want N≥10 across multiple machines and at least one
  PHP-FPM + nginx run for absolute throughput. The
  median-window metric closed enough of the noise gap that
  N=5 fires the verdict on these workloads, but the published
  numbers should still get a higher-N pass before going to a
  README.
- **No middleware in the bench.** Real apps have CORS / auth /
  rate limit / problem-details rendering. The PSR-15 ecosystem
  middleware drop-in story is the actual reason to consider
  this refactor; the bench app skips that surface entirely.
- **`php -S` is a development server.** PHP-FPM behind nginx may
  shift these numbers in either direction. The project's
  position (`bench/compare/README.md`) is that cross-framework
  numbers from this rig are useful for *relative* comparisons on
  the same harness, not as absolute truth.
- **The compiled-binder dominates request time.** Both legs share
  the binder, and `Binder::compileFor` is the single most
  expensive thing in the request path. The 3-7% PSR-7 advantage
  on the binder-driven routes likely shrinks (in either
  direction) on a hypothetical no-binder no-validation route.

## Next steps

- **Backport the `PsrAdapter` `php://input` fix to master.** Same
  bug exists there; manifests the moment anyone reads a request
  body through the existing PSR-15 escape hatch. Highest
  priority of the three.
- **Re-run the full `bench/compare` matrix** (rxn / rxn-psr7 /
  slim / symfony / raw) at N=5 to see where `rxn-psr7` lands on
  the cross-framework throughput table. If `rxn-psr7` is on par
  with `rxn` and still well above Slim / Symfony, the
  ergonomic-vs-throughput tradeoff for going PSR-7-native is
  no longer a tradeoff — it's a free upgrade.
- **Write the `Pipeline → Psr15Pipeline` default-flip commit**
  on this branch only after the cross-framework re-run confirms
  the result. The current `Http\Pipeline` would stay shipped as
  a deprecated escape hatch through one minor cycle, then
  removed.
- **Update `docs/design-philosophy.md` principle 4 and
  `docs/psr-7-interop.md`** to reflect the measurement.
  "PSR-7 is too expensive" was a credible claim before
  `PsrAdapter::serverRequestFromGlobals` existed; it isn't now.
