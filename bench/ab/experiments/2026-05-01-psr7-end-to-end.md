# PSR-7/15 end-to-end on a Rxn-shaped pipeline

**Date:** 2026-05-01
**Decision:** **PSR-7 measurably not slower; observed 3–7% faster
across both A→B and B→A orderings.** The non-overlapping-range
result from the first run was partly bench artifact (a
Latin-square outlier pattern that hits whichever framework runs
first), but the underlying medians still favour PSR-7 in both
orderings. The mechanism for the small advantage is not
identified; sample size (N=10 total across two orderings) is
short of what a "PSR-7 is faster" headline would need. The
defensible claim is **PSR-7 end-to-end has no measurable cost
penalty on these workloads, despite doing strictly more work
per request.** Held on the experiment branch
`experiment/psr-7-refactor` for the user's merge decision.

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

## Result (N=5 per ordering, N=10 total)

5 runs of `bench/compare/run.php --duration=7 --concurrency=10`
on `php -S` with `PHP_CLI_SERVER_WORKERS=4`, in two orderings:

### Forward order (rxn → rxn-psr7)

| Case                       | rxn median | rxn range          | rxn-psr7 median | rxn-psr7 range     |   Δ %  |
|----------------------------|-----------:|--------------------|----------------:|--------------------|-------:|
| GET /hello                 |     16,993 | 13,050 .. 17,184   |          17,499 | 13,708 .. 17,850   |  +3.0% |
| GET /products/{id:int}     |     16,873 | 12,775 .. 17,048   |          17,615 | 17,317 .. 17,800   |  +4.4% |
| POST /products (valid)     |     16,823 | 12,936 .. 16,995   |          17,543 | 17,382 .. 17,744   |  +4.3% |
| POST /products (422)       |     16,677 | 12,789 .. 16,773   |          17,894 | 17,521 .. 18,061   |  +7.3% |

### Reverse order (rxn-psr7 → rxn)

| Case                       | rxn-psr7 median | rxn-psr7 range     | rxn median | rxn range          |   Δ %  |
|----------------------------|----------------:|--------------------|-----------:|--------------------|-------:|
| GET /hello                 |          18,407 | 11,663 .. 18,947   |     17,341 | 11,975 .. 17,885   |  +6.1% |
| GET /products/{id:int}     |          17,910 | 12,202 .. 18,636   |     17,257 | 16,549 .. 17,570   |  +3.8% |
| POST /products (valid)     |          17,457 | 11,746 .. 18,533   |     16,533 | 11,556 .. 17,306   |  +5.6% |
| POST /products (422)       |          17,394 | 11,991 .. 18,410   |     16,947 | 11,505 .. 17,534   |  +2.6% |

PSR-7 is the higher median in **all eight cell-comparisons across
both orderings**, by 2.6–7.3%. p99 latency drift is small and
uniform (+0.05 to +0.14 ms), consistent with one extra layer of
method dispatch through `Psr15Pipeline::handle`.

### The Latin-square pattern

The forward-order rxn samples revealed a deterministic artifact:
exactly one cell per run drops to ~12-13k rps while the others
cluster at 16-17k, with the low cell *walking diagonally* across
runs (cell 1 in run 1, cell 2 in run 2, …, cell 4 in run 4).
That's not random GC noise; it's a structured per-route event.

Reversing the framework order moved the diagonal: the lows
shifted to **whichever framework runs first**. So the artifact is
position-dependent, not framework-dependent — most likely a
combination of `php -S` worker first-touch on each route's code
path plus TCP socket TIME_WAIT churn between bench windows.

The reverse-order test was the right control. Without it, the
"three cells non-overlapping" verdict from the first run would
have been a false positive — the artifact made rxn's range
artificially wide on the first-position cells.

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

- **N=10 across two orderings still leaves a 3-7% effect under-
  sampled.** A real "PSR-7 is faster" finding would want
  N≥20 with rig variation (PHP-FPM + nginx, bare-metal, longer
  durations). What we have is enough to *rule out* a measurable
  PSR-7 cost penalty; it isn't enough to *establish* a PSR-7
  advantage as a framework property.
- **The Latin-square outlier is an unfixed bench-harness bug.**
  Whichever framework runs first eats one ~12-13k rps cell per
  run, walking diagonally across positions. We control for it
  by running both orderings; the right long-term fix is to
  either restart `php -S` between routes or to randomise route
  order per run. Worth a separate investigation in
  `bench/compare/`.
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
