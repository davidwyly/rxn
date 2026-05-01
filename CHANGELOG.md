# Changelog

All notable changes to Rxn between releases. Unreleased work lives
on `claude/code-review-pDtRd` until cut to a tagged version.

The format roughly follows [Keep a Changelog](https://keepachangelog.com)
with one Rxn-specific section: **Negative results.** Performance
ideas that didn't ship still get listed — they form a library of
"don't try X for category Y" lessons that future contributors can
read alongside the wins.

## Unreleased

### Post-merge corrections

Items landed after the optimisation branch merged to `master`:

#### Fixed (early post-merge)

- **Unrouteable URLs return 404, not 500.** When the convention
  router resolved a URL to a class that didn't exist, the
  `ContainerException` from `$container->get($controller_ref)`
  bubbled up to the renderer as a 500. From the client's
  perspective that's still "no such resource"; `App::dispatch`
  now catches the resolution failure and rethrows as
  `NotFoundException` (404).
- **Convention router accepts `v1` (no trailing period).** The
  shape was tightened in the optimisation branch in a way that
  TypeError'd on the no-period form. Restored.
- **Boot is database-free again.** `Service\Registry` used to be
  eagerly resolved during `App::__construct`, forcing a MySQL
  connection on every request — including 404s and `/health`
  checks. Registry is now lazy: only the legacy `Model\Record` /
  `Data\Map` consumers pull it, and only when actually used.

#### Refactored (early post-merge)

- **`davidwyly/rxn-orm` moved from `require` to `suggest`.** Apps
  using Rxn purely for routing / DTO binding / middleware no
  longer pull in the ORM. The framework's `Database::run()` and
  `Model\ActiveRecord` type-hint `\Rxn\Orm\Builder\Buildable` /
  `\Rxn\Orm\Builder\Query`, which (per principle 11) only resolve
  when the methods are actually called — so the framework still
  loads cleanly without rxn-orm installed.
- **`Psr16IdempotencyStore` drops duck-typing.** Replaced the
  `object` parameter + `method_exists` validation with a nominal
  `\Psr\SimpleCache\CacheInterface` type-hint. PHP's lazy
  autoload of typed parameters means the framework still loads
  cleanly without `psr/simple-cache` installed; reviewers see a
  normal type-hint, no docblock gymnastics.

#### Added

- **`Response::problem(int $code, ?string $title, ?string $detail,
  ?array $validationErrors)`** — public factory for building an
  RFC 7807 Problem Details response without going through an
  exception. Used by middleware that needs to short-circuit with a
  structured failure (auth, rate limit, idempotency conflict).
- **`docs/psr-7-interop.md`** — owns the dual-stack story: why
  the framework is PSR-15-bridged rather than PSR-7-native (the
  Nyholm `with*()` clone-chain measurement, principle 4's "don't
  import PHP overhead you can't compile away," JSON-only
  narrowing) and when each stack is the right tool.

#### Fixed

- **`BearerAuth` 401 responses now actually emit
  `application/problem+json`.** Pre-fix, the middleware reached
  into `Response`'s private `$code` via reflection and populated
  `meta` (not `errors`), so `App::render` saw `isError() === false`
  and emitted a regular JSON envelope — silently violating the
  framework's RFC 7807 commitment for the auth path. The
  middleware now goes through `Response::problem()` and the wire
  shape matches every other failure path.
- **Malformed route placeholders fail at registration.** Patterns
  like `/{1bad:int}`, `/{na-me:int}`, or `/u/{:int}` previously
  fell through to `preg_quote` and silently became literal
  segments — registering an unmatchable static route the user
  never intended. Now `\InvalidArgumentException` is thrown with a
  message explaining the expected `{name}` / `{name:type}` grammar.

  *Breaking* for anyone whose codebase contains a typo of this
  shape (the route never matched anything, but registration used
  to succeed). Pre-1.0 so within the SemVer window.

#### Test coverage

- New parametric `BinderMatrixTest` runs 14 cells of
  (type × required × default × nullable × attribute) through both
  `Binder::bind` and `Binder::compileFor` and asserts identical
  outcomes. Locks the runtime/compiled paths against drift.
- New `RouterTest` adversarial-pattern data provider covers nine
  malformed / edge-case patterns; this is what surfaced the
  silent-literal-segment bug above.
- `OpenApi\GeneratorTest` snapshot test pins three controller
  paths and the Problem Details schema shape, so any drift in the
  reflection-driven generator becomes a deliberate diff.
- `BearerAuthTest::testMissingHeaderReturns401` updated to
  validate the new `application/problem+json` shape end-to-end.

#### Docs

- `docs/design-philosophy.md` — corrected the "~3,000 LOC"
  reference to "~10k LOC, ~3k LOC dispatch spine" (with the spine
  enumerated). Added a "Per-request state is sync-only by design"
  subsection making the framework's stated sync-first posture
  explicit at the middleware-static layer.
- `docs/psr-7-interop.md` — see *Added* above; documents the
  dual-stack story.
- README — promoted explicit / attribute routing as the
  recommended surface in the dispatch diagram and Features list;
  convention router still supported, demoted to a "legacy path"
  note.

### Optimisation branch (`claude/code-review-pDtRd`)

This is the largest single span of work in the repo's history:
27 merged commits across performance, framework breadth, and docs,
all on `claude/code-review-pDtRd`. Headline items below; the full
A/B writeups live under
[`bench/ab/experiments/`](bench/ab/experiments/) and the
end-to-end consolidation in
[`bench/ab/CONSOLIDATION.md`](bench/ab/CONSOLIDATION.md).

### Added

#### Schema-compiled fast paths (opt-in)

Three eval-compiled APIs that bake reflection / parsing / dispatch
into straight-line PHP. Same shape as the runtime APIs; the
optimisation is opt-in because it has a real tradeoff
(long-lived workers benefit; FPM workers reset per request).

- **`Validator::compile(array $rules): \Closure`** — runs the
  whole rule set inline with no per-call parse + switch dispatch.
  **2.45× faster** than `Validator::check` on the bench rule set.
- **`Binder::compileFor(string $class): \Closure`** — hydrates +
  validates a `RequestDto` with no runtime Reflection. Property
  names baked as literals, types specialised per-property,
  validation attributes inlined.  **6.4× faster** than
  `Binder::bind` on the bench DTO.
- **`Container` per-class factory closures** — compiled from the
  existing constructor plan. Transparent (no API change). Stacks
  on the four prior container caches for **+115% cumulative**
  vs the pre-experiments baseline.

#### Plugin middlewares

Four production-shape components closing the most-requested
plugin gaps with the framework's existing conventions
(`current()`-style static accessors, `?callable $emitHeader`
constructor args for testability, `finally`-cleared per-request
state for long-lived workers).

- **`Http\Middleware\BearerAuth`** — `Authorization: Bearer`
  enforcement on top of the existing `Service\Auth` resolver.
  401 problem details on miss; principal exposed via
  `BearerAuth::current()`.
- **`Http\Middleware\Idempotency`** — Stripe-style replay support
  for mutating endpoints. Five paths covered: cold-key store,
  matching-fingerprint replay, mismatched-body 400, in-flight 409,
  GET passthrough. Three storage shapes:
  - `Http\Idempotency\IdempotencyStore` — narrow 4-method interface
  - `Http\Idempotency\FileIdempotencyStore` — default backend
    using atomic `fopen('xb')` lock + atomic rename writes; zero
    new dependencies
  - `Http\Idempotency\Psr16IdempotencyStore` — bridge to **any**
    PSR-16-shaped cache. Constructor type-hints
    `\Psr\SimpleCache\CacheInterface` directly; PHP's lazy autoload
    of typed parameters means the framework still loads cleanly
    when `psr/simple-cache` isn't installed (the file sits inert
    until something actually instantiates it). See "principle 11"
    in `docs/design-philosophy.md` for the pattern. Apps already
    running Redis through `symfony/cache` /
    `cache/redis-adapter` / etc. drop their cache in directly.
- **`Http\Middleware\Pagination`** — parses `?limit=&offset=` or
  `?page=&per_page=` into a typed `Pagination` value object;
  emits `X-Total-Count` and RFC 8288 `Link: rel=...` headers
  when the controller passes `meta.total`.
- **`Http\Middleware\Transaction`** — wraps mutating requests in
  a database transaction; commits on 2xx, rolls back on
  4xx/5xx/throw, re-throws original exception. Composes with
  handler-level `transactionOpen()` calls via Database's
  nesting depth counter.
- **`Http\Health\HealthCheck`** — readiness/liveness route
  helper. Registers a closure-handler route on a `Router`; runs
  named callable checks (bool / array / throwing); returns
  `{data: {status, checks}, meta: {status: 200|503}}`.

#### Validator + DTO attribute expansion

Validator now covers **22 keyword rules** (was 12). 10 new ones
across both the runtime `check` path and the compiled `compile`
codegen path simultaneously: `uuid`, `ip`, `ipv4`, `ipv6`,
`json`, `date`, `datetime`, `not_blank`, `starts_with:<prefix>`,
`ends_with:<suffix>`. `date` / `datetime` are strict round-trip
via `DateTimeImmutable` (rejects `2024-02-30` and loose
`strtotime`-isms).

Eight matching DTO validation attributes for use with the
Binder: `#[Email]`, `#[Url]`, `#[Uuid]`, `#[Json]`, `#[Date]`,
`#[NotBlank]`, `#[StartsWith('prefix')]`, `#[EndsWith('suffix')]`.
Each implements `Validates` (so the runtime path picks them up
automatically) AND has a dedicated inliner in
`Binder::inlineValidator`'s match table (so the 6.4×
CompiledBinder applies for free).

#### Other shipped capabilities

- **`bin/preload.php`** — OPcache preload script. 90 classes /
  97 scripts / ~1.1MB land in shared memory at fpm boot, cutting
  cold-request latency. Wired in via `opcache.preload` in
  php.ini.
- **`bench/ab.php`** — git-worktree-based A/B microbenchmark
  driver. Runs both refs N times, reports per-case median +
  range + verdict (win / regression / noise / uncertain).
  Methodology in
  [`bench/ab/README.md`](bench/ab/README.md).
- **`bench/compare/`** — cross-framework HTTP comparison harness
  (Rxn / Slim 4 / Symfony micro-kernel / raw PHP) under `php -S`
  with a curl_multi load generator.
- **`examples/products-api/`** — eight-file worked example
  exercising every shipped middleware in five minutes. Front
  controller in
  [`examples/products-api/public/index.php`](examples/products-api/public/index.php).
- **`docs/design-philosophy.md`** — the working theory of how
  Rxn lands fast + readable + small at the same time. Ten
  principles, each cross-referenced to where it shows up in the
  code, plus an anti-patterns list of decisions deliberately
  avoided.

### Changed (transparent)

These optimisations stack invisibly — existing code that uses the
public APIs got faster without recompiling, reconfiguring, or
even knowing.

- **Router** — verb-bucket dispatch (routes registered for `GET`
  land in a `GET` bucket so `match` skips straight to the
  verb-relevant slice) plus an O(1) static-path hashmap shortcut.
  Worst-case dispatch on the bench's 20-route many-case
  improved **+286%** (637K → 2.46M ops/s);
  `router.match.many.last_verb_hit` improved **+249%**.
  Registration-order semantics preserved.
- **Container** — five stacked transparent optimisations:
  ReflectionClass cache, constructor-plan cache, parseClassName
  cache, hot-path trim (precomputed self-key constant + inline
  instance write), per-class compiled factory closures.
  Cumulative: **+115%** vs pre-experiments baseline (~323K → ~720K
  ops/s on a quiet box).
- **`PsrAdapter::serverRequestFromGlobals`** — direct
  `ServerRequest` constructor instead of Nyholm's 14-clone
  immutable-builder chain. **+124%** (55K → 123K ops/s).
- **`ActiveRecord::hydrate`** — inline foreach over rows
  replaces `array_map + closure + ::fromRow`. **+88%** (99K
  → 187K ops/s on `hydrate_100`).
- **`Validator::check`** — `strpos + substr` rule parse replaces
  `array_pad + explode`. Modest **+8%** on the runtime path; the
  bigger win is the new compile path.
- **`Database::transactionRollback`** — promoted from `private`
  to `public`. The asymmetry with `transactionOpen` /
  `transactionClose` was an oversight that blocked the
  `Transaction` middleware from existing.
- **`Validator` / `Binder` callable-rule check** — required
  `!is_string($rule) && is_callable($rule)` instead of just
  `is_callable($rule)`. Pre-fix, bare strings whose names
  matched PHP builtins (`'date'`, `'time'`) were treated as
  function calls, which crashed when used as rule names.
  Regression test included.

### Cross-framework comparison

Pure-PHP HTTP throughput, `php -S` per-request worker mode (full
table + methodology in
[`bench/ab/CONSOLIDATION.md`](bench/ab/CONSOLIDATION.md)):

| Framework | GET /hello | GET /products/{id} | POST valid | POST 422 |
|---|---:|---:|---:|---:|
| **rxn** | **8,167** | **8,439** | **9,312** | **9,391** |
| raw PHP (no framework) | 6,757 | 8,450 | 8,162 | 7,297 |
| symfony micro-kernel | 4,218 | 4,158 | 4,012 | 4,022 |
| slim 4 | 3,796 | 3,865 | 3,749 | 3,759 |

≈ 2× the throughput of Slim and Symfony, on par with hand-rolled
PHP, 2-3× lower p99 latency.

### Test coverage

The branch nearly doubled the test count, primarily through
parity tests that prove runtime and compiled paths agree
byte-for-byte across data-provider cases.

- Tests: 224 → **371** (+66%)
- Assertions: 521 → **813** (+56%)
- A/B experiment writeups: **16**
- Negative-result branches preserved on origin: **4**

### Negative results (preserved on origin)

These didn't ship but the lessons did:

| Branch | Verdict | Lesson kept |
|---|---|---|
| `bench/ab-router-combined-alternation` | **−23% on common case** | Combined PCRE alternation pessimises the bucket-hit common case; verb buckets were the right call |
| `bench/ab-psr-adapter-factory-cache` | +1.2%, noise | One-allocation cache invisible when the dominant cost is constructor work; pointed the way to the eventual direct-construction +124% win |
| `bench/ab-pipeline-no-array-reverse` | +0.3%, noise | `array_reverse` on 3 elements is sub-50ns; closure allocation dominates |
| `bench/ab-compiled-json-encoder` | **−35% to −43%** | **Schema-compilation only beats baselines that are also user-space.** PHP's C-level `json_encode` cannot be caught from PHP — full stop. This lesson directly steered CompiledValidator → CompiledBinder, both of which won big on pure-PHP baselines |

### Methodology

Every shipped optimisation has an A/B run with worktree-based
comparison, ranges, and a non-overlapping-range verdict
([`bench/ab.php`](bench/ab.php)). Negative-result branches stay
on origin with their writeups. The heuristic for "verdict = win":

```
|Δ| > 5% AND non-overlapping [min, max] ranges across N runs
```

The harness pays for itself on the first regression it catches —
the combined-alternation router "looked obviously good" (one
preg_match instead of N) but regressed the common bucket-hit case
by 23%. Without the harness, that change would have shipped.

---

## Pre-branch baseline

Numbers from the run immediately before this branch's first
commit, for reference:

```
case                                  ops/sec
------------------------------ --------------
router.match.static                 1,606,128
router.match.many.last_verb_hit       547,012
router.match.many.miss                637,868
container.get.depth_3                 323,050
validator.check.clean                 328,200
active_record.hydrate_100              99,562
psr7.from_globals                      55,000
```

Compare to the post-branch numbers in
[`docs/benchmarks.md`](docs/benchmarks.md).
