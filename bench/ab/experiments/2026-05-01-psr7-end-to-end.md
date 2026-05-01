# PSR-7/15 end-to-end on a Rxn-shaped pipeline

**Date:** 2026-05-01
**Decision:** **Preliminary** — parity to slight PSR-7 advantage at
N=3, no measurable regression. Held on the experiment branch
`experiment/psr-7-refactor` for re-validation at N=5+ before any
merge consideration.

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

## Result (preliminary, N=3)

3 runs of `bench/compare/run.php --duration=5 --concurrency=10`
on `php -S` with `PHP_CLI_SERVER_WORKERS=4`. Per-cell median
throughput:

| Case                       | rxn (control) | rxn-psr7 (experiment) |   Δ %  |
|----------------------------|--------------:|----------------------:|-------:|
| GET /hello                 |        16,916 |                18,050 |  +6.7% |
| GET /products/{id:int}     |        16,787 |                17,198 |  +2.4% |
| POST /products (valid)     |        17,135 |                17,394 |  +1.5% |
| POST /products (422)       |        17,038 |                17,288 |  +1.5% |

p99 latency drift was uniformly small (+0.05 to +0.14 ms) and is
consistent with one extra layer of method dispatch through
`Psr15Pipeline::handle`.

Per-cell ranges overlap on three of four cells, so by the
project's own non-overlapping-range verdict
(`bench/ab/CONSOLIDATION.md`) the only honest claim at N=3 is
**parity, not improvement**. The control's range was wider than
the experiment's (e.g. one rxn `GET /hello` sample at 9,988 rps
vs the rest at 16-18k), suggesting `php -S` worker thrash that
the PSR-7 path happens to ride out better.

## Why this matters

The framework's stated reason for not going PSR-7-native — the
`with*()` clone-chain cost — is real *for the default Nyholm
builder*. `PsrAdapter` already eliminates that cost in its
`serverRequestFromGlobals` fast path. With that fast path in
place, **the residual end-to-end PSR-7 overhead is not visible
above bench noise on these workloads.**

That re-frames the design philosophy claim. The right
formulation is no longer "PSR-7 is too expensive" — it's
"Nyholm's *default* construction is too expensive, and we own a
fast-path adapter that closes the gap." The dual-stack
(`Http\Pipeline` + `Psr15Pipeline`) is justified by **ecosystem
shape and ergonomics**, not by per-request cost.

## What's not in this verdict

- **N=3 is too small.** A higher-N follow-up is queued; verdict
  upgrades to merged or rolled-back depending on whether the
  ranges sharpen.
- **No middleware in the bench.** Real apps have CORS / auth /
  rate limit / problem-details rendering. The PSR-15 ecosystem
  middleware drop-in story is the actual reason to consider
  this; the bench app skips that surface entirely.
- **`php -S` is a development server.** PHP-FPM behind nginx may
  shift these numbers in either direction. The project's
  position (`bench/compare/README.md`) is that cross-framework
  numbers from this rig are useful for *relative* comparisons on
  the same harness, not as absolute truth.
- **Compiled-binder output is the bottleneck.** Both legs of the
  A/B share the binder. If PSR-7 is parity here, that's because
  the binder dwarfs the ingress / egress cost — *not* because
  PSR-7 is free in the absolute sense. On a hypothetical
  no-binder no-validation route the gap could widen.

## Next steps

- Re-run at N=5, duration=7s. If the cells still overlap, mark
  the verdict "noise / parity, no measurable regression" and
  decide whether the dual-stack should collapse to PSR-7-only
  on ergonomic grounds alone.
- Backport the `PsrAdapter` `php://input` fix to master — same
  bug exists there, manifests the moment anyone reads a request
  body through the PSR-15 escape hatch.
- If the parity verdict holds, write the
  `Pipeline → Psr15Pipeline` migration commit on the experiment
  branch and re-run the full `bench/compare` matrix to see
  whether `rxn-psr7` lands above or below `slim` / `symfony` on
  the cross-framework table.
