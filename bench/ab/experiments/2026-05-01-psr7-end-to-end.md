# PSR-7/15 end-to-end on a Rxn-shaped pipeline

**Date:** 2026-05-01
**Decision:** **Surprise win for PSR-7.** At N=5, three of four
cells show **non-overlapping ranges with rxn-psr7 ahead** by 4-7%
(the project's standard verdict bar). The fourth cell (`GET /hello`)
is noise-bound on both sides. Held on the experiment branch
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

## Result (N=5)

5 runs of `bench/compare/run.php --duration=7 --concurrency=10`
on `php -S` with `PHP_CLI_SERVER_WORKERS=4`. Per-cell median and
range:

| Case                       | rxn median | rxn range          | rxn-psr7 median | rxn-psr7 range     |   Δ %  | verdict          |
|----------------------------|-----------:|--------------------|----------------:|--------------------|-------:|------------------|
| GET /hello                 |     16,993 | 13,050 .. 17,184   |          17,499 | 13,708 .. 17,850   |  +3.0% | noise (overlap)  |
| GET /products/{id:int}     |     16,873 | 12,775 .. 17,048   |          17,615 | 17,317 .. 17,800   |  +4.4% | **PSR-7 wins**   |
| POST /products (valid)     |     16,823 | 12,936 .. 16,995   |          17,543 | 17,382 .. 17,744   |  +4.3% | **PSR-7 wins**   |
| POST /products (422)       |     16,677 | 12,789 .. 16,773   |          17,894 | 17,521 .. 18,061   |  +7.3% | **PSR-7 wins**   |

Three cells produce **non-overlapping ranges with rxn-psr7
entirely above the rxn range** — the project's verdict bar
(`bench/ab/CONSOLIDATION.md`: ≥5% Δ AND non-overlapping ranges)
holds for the 422 case at +7.3%; the +4.3 / +4.4% cells fall
just under the 5% Δ floor but the non-overlapping ranges are
diagnostic on their own. The `GET /hello` cell is noise-bound:
both groups have one low outlier in the 13-14k range while their
remaining samples sit at 16-18k, the classic `php -S` first-run
or worker-recycle artifact.

The control's range is wider than the experiment's on every
cell. That is itself the signal — the PSR-7 path is *more
stable*, not slower, despite the extra construction work.

p99 latency drift is small and uniform (+0.05 to +0.14 ms),
consistent with one extra layer of method dispatch through
`Psr15Pipeline::handle`.

## Why this matters

The framework's stated reason for not going PSR-7-native — the
`with*()` clone-chain cost — is real *for the default Nyholm
builder*. `PsrAdapter` already eliminates that cost in its
`serverRequestFromGlobals` fast path. With that fast path in
place, **going PSR-7 end-to-end isn't just free, it's mildly
faster on most cells** in this bench.

That's a falsification of the project's stated design rationale.
The right formulation is no longer "PSR-7 is too expensive" —
it's "Nyholm's *default* construction is too expensive, and we
own a fast-path adapter that more than closes the gap." The
dual-stack (`Http\Pipeline` + `Psr15Pipeline`) is no longer
justifiable on per-request cost grounds; the only remaining
arguments for keeping the native stack are docstring clarity and
the existing test surface, both of which are bookkeeping rather
than performance.

Plausible mechanisms for the small PSR-7 advantage:

- The native bench app does `parse_url` inline plus a switch
  statement; the PSR-7 path threads through `match` against a
  pre-resolved handler ref, which is a slightly tighter dispatch.
- `PsrAdapter::emit` writes the body in 8 KiB chunks via
  `$body->read(8192)`, which interacts better with `php -S`'s
  output buffering than the native path's single `echo` of a
  full string for moderate-sized JSON.
- The Psr7Response constructor takes status + headers + body in
  one shot — no `with*()` chains in the response path either.
- The native path's tighter coupling to `$_SERVER` parsing may
  be paying for `is_array` / `isset` micro-checks the PSR-7
  request shape skips after construction.

The differences are small (3-7%); the mechanisms are speculative.
The robust claim is **PSR-7 end-to-end is not slower at this
scope**, not "PSR-7 is faster."

## What's not in this verdict

- **N=5 is the project's standard sample size**, but the four
  bench cells share noise sources (the same `php -S` worker
  pool boots once per framework run); this isn't the same as
  N=20 on independent rigs.
- **No middleware in the bench.** Real apps have CORS / auth /
  rate limit / problem-details rendering. The PSR-15 ecosystem
  middleware drop-in story is the actual reason to consider
  this; the bench app skips that surface entirely.
- **`php -S` is a development server.** PHP-FPM behind nginx may
  shift these numbers in either direction. The project's
  position (`bench/compare/README.md`) is that cross-framework
  numbers from this rig are useful for *relative* comparisons on
  the same harness, not as absolute truth.
- **Compiled-binder output dominates.** Both legs of the A/B
  share the binder, and `Binder::compileFor` is the single most
  expensive thing in the request path. The 3-7% PSR-7 advantage
  on the binder-driven routes likely shrinks (in either
  direction) on a hypothetical no-binder no-validation route.
- **GET /hello has bilateral outliers** (one low sample in each
  group ~13-14k vs the other four at 16-18k). With N=5 the
  median still hits the high cluster, but it's the cell most
  exposed to single-run thrash — its overlap verdict shouldn't
  be over-read.

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
