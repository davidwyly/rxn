# Changelog

All notable changes to Rxn between releases. Unreleased work lands
on short-lived `experiment/*` branches and merges to `master` via
PR; the next tagged version cuts from `master`.

The format roughly follows [Keep a Changelog](https://keepachangelog.com)
with one Rxn-specific section: **Negative results.** Performance
ideas that didn't ship still get listed — they form a library of
"don't try X for category Y" lessons that future contributors can
read alongside the wins.

## Unreleased

### Strip convention router + legacy AR layer (`chore/strip-convention-router`)

The convention router (`App::run()`, `Service\Api`, `Service\Stats`,
`Service\Auth`, `Service\Registry`, `Model\Record`, `Model\ActiveRecord`,
`Model\Model`, `Data\Database`, `Data\Map`, `Data\Migration`, `Data\Chain`,
`Data\Filecache`, `Data\Query`, `Http\Controller`, `Http\CrudController`,
`Http\Collector`, `Http\Request`, `Http\Response`,
`Http\Middleware\Transaction`, `Http\Router\Session`, `Startup`,
`Config`, `BaseConfig`, `Service` base class) is gone. The framework
ships a single entry point: `App::serve(Router)`. Boot-free, no
constructor, no Container plumbing during request setup, no DB
connection unless an app wires one.

This is a WIP-era cleanup, not a deprecation cycle. No
backwards-compat shims; apps that depended on the convention router
need to migrate to `App::serve(Router)` + `#[Route]` attributes or
explicit `$router->get(...)` registration.

#### Removed (code)

- **Convention router stack:** `App::run()`, `App::dispatch()`,
  `App::renderFailure()`, `App::__construct()`, `App::render()`,
  `App::renderEnvironmentErrors()`, `App::appendEnvironmentError()`,
  `App::isProductionEnvironment()`, `App::hasEnvironmentErrors()`,
  `App::getElapsedMs()`. `App` is now a static-only class with
  `serve()`, `arrayToPsrResponse()`, `psrProblem()`, and a few
  private helpers.
- **Service base + concrete services:** `Rxn\Framework\Service`,
  `Service\Api`, `Service\Auth`, `Service\Registry`, `Service\Stats`,
  `Startup`, `Config`, `BaseConfig`.
- **Legacy data layer:** entire `Rxn\Framework\Data\*` namespace
  (`Database`, `Map`, `Map\Table`, `Map\Chain\Link`, `Chain`,
  `Migration`, `Migration\Schema`, `Migration\Schema\Version`,
  `Filecache`, `Query`).
- **Legacy model layer:** entire `Rxn\Framework\Model\*` namespace
  (`Record`, `ActiveRecord`, `Model`).
- **Legacy HTTP shapes:** `Http\Controller`, `Http\CrudController`,
  `Http\Controller\Crud`, `Http\Collector`, `Http\Request` (the
  non-PSR-7 one), `Http\Response` (the non-PSR-7 one),
  `Http\Middleware\Transaction`, `Http\Router\Session`.
- **Demo scaffolding:** `app/`, `examples/`, `public/index.php`,
  `docker/`, `docker-compose.yml`, `docker-compose.env.example`.

#### Refactored

- **`App::serve()`** — kept; now the only entry point. Modern PSR-7
  flow unchanged.
- **`Container`** — dropped the `isService` singleton/transient split
  (it depended on the deleted `Service` base class). All resolved
  classes are now cached as singletons; this is the standard
  PSR-11 / DI-container behaviour.
- **`Http\Middleware\BearerAuth`** — no longer depends on
  `Service\Auth`. Constructor takes a `callable(string): ?array`
  resolver directly. Apps that want token introspection / cache
  hits / JWT verify wrap that in the resolver closure.
- **`composer.json`** — dropped `ext-pdo` (Database is gone),
  dropped `davidwyly/rxn-orm` from `require-dev` (was needed for
  the deleted ActiveRecord tests). Updated the rxn-orm `suggest`
  description to drop references to deleted Data\Database etc.
- **`bin/rxn`** — dropped subcommands `migrate`, `migrate:status`,
  `make:controller`, `make:record` (each depended on deleted
  framework code). Remaining commands: `openapi`, `openapi:check`,
  `routes:check`, `dump:hot`.
- **`bin/bench`** — dropped the storage-flavoured cases that
  required `davidwyly/rxn-orm` and the deleted `Model\ActiveRecord`
  (`builder.select.compound`, `builder.select.subquery`,
  `builder.insert.multirow`, `builder.update.simple`,
  `builder.delete.simple`, `active_record.hydrate_100`). Those are
  benchmarking external code; the rxn-orm repo's own bench harness
  is the right home. Framework primitives still benched: Router,
  Pipeline, Container, Binder, Validator, PsrAdapter.

#### Removed (tests)

24 test files for the deleted code:
`AppBootTest`, `AppErrorHandlingTest`, `Tests/Service/*`
(3 files), `Tests/Model/*` (2), `Tests/Data/*` (6),
`Tests/Http/CollectorTest`, `ControllerTest`, `CrudControllerTest`,
`RequestTest`, `ResponseTest`, `Tests/Http/Middleware/TransactionTest`,
`Tests/Http/Router/SessionTokenTest`,
`Tests/Http/Binding/ProblemDetailsIntegrationTest` (used legacy
Response). `BearerAuthTest` rewritten for the callable-resolver
constructor; `NotFoundExceptionTest` lost one stale-Response
assertion.

#### Numbers

- Suite: 739 → 617 / 1324 (122 tests gone with the deleted code, 1 added for the parameterised-get cache contract).
- Framework LOC: ~13K → ~11K (~2K LOC removed).
- Middleware count: 9 → 8 (Transaction dropped).
- Composer requires shrunk: `ext-pdo` removed; `require-dev`
  dropped `davidwyly/rxn-orm`.

#### Doc updates

- `docs/scaffolding.md` — deleted (the feature is gone).
- `docs/routing.md` — dropped the convention-router section;
  `Http\Router` is now the only routing primitive documented.
- `docs/index.md` — rewrote the request-lifecycle diagram around
  `App::serve(Router)`.
- `docs/building-blocks.md` — pruned the legacy data/model/orm
  sections; pointer to `davidwyly/rxn-orm` for query-builder /
  ActiveRecord; dropped Transaction middleware section.
- `docs/design-philosophy.md` — refreshed the "where each
  principle shows up" table.
- `README.md` — refreshed quickstart to a minimal `App::serve(Router)`
  shape; dropped Docker stack + curl walkthrough (relied on the
  deleted demo); refreshed test counts + middleware list.

---

### Observability event surface (`feat/otel-spans-via-psr14`)

Eight first-party events emitted from the framework's request
lifecycle, dispatched through PSR-14. Apps subscribe a single
listener on the `FrameworkEvent` marker interface and receive
the lot — request bookends, per-middleware brackets, route-match
metadata, binder invocation + validation outcome, handler
brackets — without bolting on per-component instrumentation.

This is the foundation for [horizons.md theme 2.1](docs/horizons.md)
(OpenTelemetry spans) and theme 2.2 (Prometheus metrics). Both
are listeners over the same event channel; this PR ships the
substrate. The events themselves carry no OTel / Prometheus
dependency — they're plain value objects, so apps that don't
subscribe pay zero runtime cost beyond a static-slot null check
per emit.

#### Added

- **`Rxn\Framework\Observability\Events`** static helper:
  `useDispatcher()` installs a PSR-14 dispatcher (no-op until
  installed); `emit()` is the per-call-site dispatch helper that
  short-circuits when no dispatcher is set; `newPairId()` mints
  a 64-bit hex id for bracketing entered/exited boundaries.
- **`Rxn\Framework\Observability\Event\FrameworkEvent`** marker
  interface — listeners that want every event subscribe once on
  this. The PSR-14 ListenerProvider walks implemented interfaces
  during dispatch, so a single registration covers every concrete
  event type.
- **Eight concrete events** (all under `Observability\Event\*`):
  `RequestReceived` / `ResponseEmitted` (request bookends, paired
  by id), `MiddlewareEntered` / `MiddlewareExited` (one pair per
  middleware in the stack, with `$index` and `$throwable` on
  failure), `RouteMatched` (template + extracted params on a
  successful match), `BinderInvoked` (with `path` tag —
  `compiled` or `runtime` — for compile-cache hit-ratio
  metrics), `ValidationCompleted` (failures grouped by field
  name; fires on success and failure, both runtime and compiled
  paths), `HandlerInvoked` (entered/exited with throwable on
  handler failure).
- **Dispatch wiring** in `Pipeline::handle` (per-middleware
  bracket, exit-on-throw), `Router::match` (both static fast
  lane and regex bucket), `Binder::bind` (both runtime walker
  and compiled path), `App::serve` (request + handler
  bookends).

#### How apps wire it

```php
$provider   = new ListenerProvider();
$dispatcher = new EventDispatcher($provider);

// Register one listener for every framework event:
$provider->listen(FrameworkEvent::class, $myListener);

// Or target a specific event:
$provider->listen(ValidationCompleted::class, function (ValidationCompleted $e): void {
    if ($e->isFailure()) {
        $myMetrics->increment('validation_failures', $e->failures);
    }
});

Events::useDispatcher($dispatcher);
```

#### What it costs

- **Per emit point when no dispatcher installed:** one
  `Events::enabled()` bool read. The event object is NOT
  constructed and `random_bytes(8)` (for pair-id minting) is
  NOT called — the call site short-circuits before either.
  Apps that don't subscribe pay roughly nothing per request
  hop.
- **Per emit with a no-op listener:** event-object allocation
  (a few field copies) + one method call + iterator walk
  (~50 ns total). Negligible against a request's overall cost.
- **Hottest path preservation:** `Binder::bind()`'s compiled
  fast path returns the closure invocation directly when
  observability is disabled — no `try/catch` frame, no
  intermediate `$dto` assignment.
- **No new dependencies.** Leans on the existing PSR-14 wiring
  (`Rxn\Framework\Event\EventDispatcher` + `ListenerProvider`).

#### Tests

- 8 unit tests for `Events` (no-op without dispatcher, dispatch
  through, accessor, pair-id format, stoppable semantics, custom
  dispatcher implementation, `enabled()` gate, `currentPairId()`
  round-trip).
- 5 Pipeline integration tests (one pair per middleware, ordered,
  pair-id consistency, exit-on-throw, FQCN populated, no-op
  without dispatcher).
- 4 Router integration tests (static path, dynamic params, miss,
  method-mismatch).
- 4 Binder integration tests (runtime path, compiled path,
  failure grouping by field, compiled path emits on failure too).
- 6 App::serve integration tests (full success path event tree,
  404 miss path, 405 method-mismatch path, pair-id slot
  cleared in finally, no-op without dispatcher, invokable
  handler label).

Suite 712 → 739 / 1598.

---

### Profile-guided compilation (`feat/profile-guided-compilation`)

Track which DTOs are actually hot at runtime; compile only
those into the dump cache. Cold paths stay on the runtime
walker — opcache memory pays only for hot DTOs, not the
graveyard of rarely-hit classes that ships with most apps.

Closes [horizons.md theme 3.1](docs/horizons.md). The horizons
doc framed this as academic-compiler-paper territory: no PHP
framework I know does it. The DumpCache + `compileFor()`
infrastructure already existed; this PR is the measurement +
selection layer that turns "compile everything at boot" into
"compile what matters."

#### Added

- **`Rxn\Framework\Codegen\Profile\BindProfile`** — in-memory
  hit counter + atomic JSON persistence. `record($class)` is
  the runtime increment (~50 ns, called from `Binder::bind()`).
  `flushTo($path)` merges the in-memory counter with any
  existing profile at `$path` via temp-file + `rename(2)` (so
  concurrent workers contribute hits without stomping on each
  other). `topK($k)` picks the hottest classes deterministically
  (count desc, name asc).
- **`Binder::warmFromProfile(string $path, int $topK)`** —
  loads a profile, picks the top-K classes, calls `compileFor()`
  on each → populates the in-memory compiled cache; when
  `DumpCache::useDir()` is configured, compileFor also writes a
  `.php` file (best-effort: closures whose validator args can't
  be dumped fall back to in-process eval, which still runs the
  compiled fast path but doesn't survive worker boot). Returns
  the list of classes that were actually warmed (stale entries
  from refactored / removed classes are silently filtered).
- **`Binder::bind()` auto-dispatch** — when a class has a
  compiled closure in the in-memory cache, `bind()` uses it
  instead of walking reflection. This is what makes the
  speedup actually land at runtime — without it, profile-guided
  compilation would just write files nobody reads.
- **`bin/rxn dump:hot`** — CLI bridge between profile capture
  and DumpCache. `--profile=PATH` (required), `--top=N`
  (default 20), `--cache=DIR` (default `var/cache/rxn`).
  Designed for post-deploy hooks: capture the profile in
  production via periodic `flushTo()`, then run dump:hot in
  the deploy pipeline.

#### How apps wire it

```php
// Bootstrap (per worker):
DumpCache::useDir('/var/cache/rxn');
if (file_exists('/var/cache/rxn/profile.json')) {
    Binder::warmFromProfile('/var/cache/rxn/profile.json', 20);
}

// Periodic flush (e.g. shutdown handler, every 1000 requests):
BindProfile::flushTo('/var/cache/rxn/profile.json');

// Post-deploy (CI hook):
bin/rxn dump:hot --profile=/var/cache/rxn/profile.json --top=20
```

#### What it costs

- **Per `bind()` call:** one array key write (~50 ns). Runs
  whether or not a profile is configured.
- **Per warm bootstrap:** O(K) `compileFor()` calls, each
  amortised by DumpCache's content-addressed file lookup (no
  recompile if the source is unchanged).
- **Per profile flush:** one temp-file write + `rename(2)`.
  Atomicity guaranteed within a single filesystem.

#### Tests

- 12 unit tests for BindProfile (record, topK with ties, JSON
  persistence atomicity, merge-on-flush, load replaces
  in-memory, defensive load drops malformed entries, reset).
- 6 Binder integration tests (bind records hits, bind
  auto-dispatches to compiled cache, warmFromProfile compiles
  top-K, stale-class entries silently skipped, non-RequestDto
  entries silently skipped, post-warm counter resets so first
  flush doesn't double-count seeds).
- 4 CLI integration tests (--profile required, missing-file
  exit-2, full compile flow, empty-profile no-op).

Suite 687 → 708 / 1504.

### W3C Trace Context propagation (`feat/trace-context-propagation`)

[W3C Trace Context](https://www.w3.org/TR/trace-context/) — the
cross-vendor protocol every distributed-tracing backend
(Jaeger, Honeycomb, Datadog, Tempo, …) speaks — now Just Works
without any app code. Drop the middleware in the pipeline and:

- Inbound `traceparent` is parsed, validated, and made
  available via `TraceContext::current()` and the request
  attribute `rxn.trace_context`.
- Malformed or absent inbound headers fall back to a freshly-
  generated context (W3C-compliant random 32-hex trace-id +
  16-hex parent-id).
- Outbound calls via `Concurrency\HttpClient` automatically
  carry `traceparent` (with a freshly-advanced parent-id, per
  spec — the current server becomes the parent of the next
  hop) AND `tracestate` (verbatim pass-through).
- The response echoes `traceparent` so calling services can
  verify trace continuity.

Closes [horizons.md theme 2.3](docs/horizons.md). Foundation
for themes 2.1 (OTel spans via PSR-14) and 2.2 (Prometheus
metrics) — every span/metric emitted by those needs a trace-id.

#### Added

- **`Rxn\Framework\Http\Tracing\TraceContext`** — W3C-
  compliant value object with `fromHeader()`, `generate()`,
  `withNewParent()`, `toHeader()`, `isSampled()`. Validates
  the version-aware traceparent format, accepts higher
  versions (forward-compat per spec), rejects the all-zero
  sentinels (the spec's "invalid" markers), normalises hex
  to lowercase. Re-emits `00` regardless of inbound version.
- **`Rxn\Framework\Http\Middleware\TraceContext`** — PSR-15
  middleware. Static `current()` slot for downstream code
  (HttpClient propagation, future logger correlation).
  Tracestate verbatim pass-through with a 512-char cap to
  avoid emitting headers gateways will reject.
- **`Concurrency\HttpClient::applyTraceContext()`** —
  outbound header injector. Caller-supplied `traceparent`
  wins (including case-variant `Traceparent`) so explicit
  per-call tracing isn't stomped on.
- 26 tests across 3 files: 13 for the value object's spec
  compliance (each parsing rule, sentinel rejections, version
  forward-compat, round-trip property, sampling bit), 7 for
  the middleware (header-absent fallback, valid-header
  honour, malformed-header fallback, request-attribute,
  tracestate pass-through, oversize tracestate dropped,
  static-slot reset), 6 for HttpClient propagation (no-op,
  injection, caller-supplied wins, case-insensitive
  preservation, tracestate forwarding).

#### Why this matters

Distributed tracing is the difference between "the request
took 4 seconds, idk why" and "spent 3.7 seconds in the slow
JOIN on the orders service." Every modern observability
stack assumes you've got trace context propagation working.

PHP frameworks historically punt this to userspace: Symfony
needs `OpenTelemetryBundle`, Laravel has third-party
packages, Slim has nothing native. With this in core, an
Rxn app drops the middleware in and is immediately a good
citizen of the distributed-tracing world — wired to whatever
OTel-compatible exporter the ops team picks, without writing
tracing code.

Pairs naturally with `RequestId` (per-request correlation
id), `BearerAuth` (request-scoped principal), and the
existing PSR-14 event surface. All four are sync-only static
slots — the ergonomics that make per-request data accessible
without threading it through every function signature.

### Compile-time route conflict detection (`feat/route-conflict-detector`)

`bin/rxn routes:check` finds overlapping `#[Route]` patterns at
CI time, not at first request. Closes
[horizons.md theme 3.2](docs/horizons.md). Most PHP frameworks
resolve route ambiguity at first-match-after-cache-warm — a
typo manifests as a silently-dead route the developer thinks is
wired up. This catches the typo before the deploy.

#### Added

- **`Rxn\Framework\Http\Routing\ConflictDetector`** — reflects
  every `#[Route]` attribute across a list of controller classes
  and runs a pairwise overlap check. The algorithm uses a static
  compatibility matrix derived from the constraint regexes (e.g.
  `int = \d+` and `alpha = [a-zA-Z]+` have empty intersection;
  `slug = [a-z0-9-]+` and `uuid = [0-9a-f]{8}-...` overlap
  because lowercase hex + dashes are slug-legal). Custom
  constraint types (added via `Router::constraint()`) are
  conservatively treated as overlapping with everything — false
  positives are preferable to false negatives in a CI gate.
- **`bin/rxn routes:check`** subcommand — exit 0 = no conflicts,
  exit 1 = conflicts found (printed with both source files +
  line numbers). Matches the discovery shape of `bin/rxn
  openapi`: `--ns=NS` and `--root=DIR` flags work the same way.
- `ConflictDetector` test suite (17 tests) covering each rule:
  the matrix corners (int vs alpha, alpha vs uuid, slug vs
  uuid, any vs everything), static-vs-dynamic literal matching,
  trailing-slash normalisation, custom-type conservatism,
  reported-once invariant on N-way conflict triplets,
  description rendering. Plus 2 CLI integration tests covering
  the clean and conflicting fixture flows.

#### Why this matters

A `#[Route]` typo today fails silently — the wrong route wins,
production swallows the bug as "this endpoint doesn't get hit,"
and the developer notices it next sprint when a customer
complains. With `routes:check` in CI, the typo blocks the
merge. Routing ambiguity becomes a compile-time concern instead
of a runtime mystery.

Pairs with the existing schema-as-truth gates (`openapi:check`,
the cross-language validator parity test): three structural
linters that catch three classes of silent-regression bugs
before deploy, all framework-native, all dependency-free.

### OpenAPI snapshot contract (`feat/openapi-snapshot-contract`)

The cheapest possible governance layer for schema-as-truth:
PR opens → CI runs `bin/rxn openapi:check` → diffs the regenerated
spec against the `openapi.snapshot.json` committed in the repo.
Drift fails the build unless the change is intentional (refresh
the snapshot with `--update`) or explicitly opted-in (the CLI
honours `--allow-breaking` so the gate can be downgraded to a
warning when the project owner accepts a known break, e.g. via
a PR label).

Closes [horizons.md theme 1.3](docs/horizons.md). Decision
criteria from that doc met: `~250 LOC including tests`, no new
dependencies, classifier conservatism (ambiguous removals → mark
as breaking) so the gate fails loudly rather than silently
passing real regressions.

#### Added

- **`Rxn\Framework\Codegen\Snapshot\OpenApiSnapshot`** —
  `serialise(array $spec): string` produces a byte-stable
  JSON serialisation (recursive ksort on maps, list order
  preserved, pretty-printed, trailing newline). `diff(array
  $old, array $new): SnapshotDiff` walks paths + components
  and classifies each change as breaking (operation/parameter/
  property removals, type changes, required-toggles to
  required) or additive (new operations, new optional fields).
- **`bin/rxn openapi:check`** subcommand — three exit codes:
  0 = no drift, 1 = additive only (or breaking with
  `--allow-breaking`), 2 = breaking changes detected (gate).
  `--update` overwrites the snapshot when changes are
  intentional. `--snapshot=PATH` overrides the default
  `openapi.snapshot.json` location.
- `OpenApiSnapshotTest` (19 unit tests) — covers serialisation
  determinism, list-order preservation, and one assertion per
  classification rule (operation removed, path removed,
  required parameter added, optional parameter added,
  parameter type change, parameter became required, parameter
  removed, schema removed, property removed, required property
  added, optional property added, property became required,
  property type change, summary-only changes ignored).
- `CliTest` integration tests (5) — drives the full
  `openapi:check` flow via shell against a sandbox controller:
  `--update` writes the snapshot, re-running with no drift
  exits 0, removing an operation exits 2, `--allow-breaking`
  downgrades to exit 1, missing snapshot file exits 2.

#### Why this matters

Most schema drift is accidental. This is the kind of feature
experienced engineering teams immediately recognise as
load-bearing — closer to a linter than a feature, but the
absence of it is what lets a routine PR ship a contract break
that the frontend team finds out about in production. With
this in CI, the PR-author intent ("yes, I am breaking the
contract") is explicit instead of latent.

Pairs with `JsValidatorEmitter` and `PolyparityExporter` —
all three consume the same source of truth (the `RequestDto`),
all three give different downstream artifacts (server runtime,
JS twin, polyparity YAML, snapshot diff). The schema is the
contract; the contract is the product.

### Polyparity exporter (`feature/polyparity-exporter`)

#### Added

- **`Rxn\Framework\Codegen\PolyparityExporter`** — emits a
  [polyparity](https://github.com/davidwyly/polyparity) YAML
  spec from any `RequestDto`. Same coverage matrix as
  `JsValidatorEmitter` (Required, NotBlank, Length, Min, Max,
  InSet, Email, Url + scalar types), same refusal on out-of-
  scope attributes. The bridge that lets one DTO drive Rxn's
  PHP server, the JS twin, AND polyparity's TS / Python /
  future-language siblings.
- `PolyparityExporterTest` — snapshot tests for `ParityDto`,
  `KitchenSinkDto`, `NumericEdgeDto`. Refusal test for
  `UnsupportedDto` (uses `#[Pattern]`).
- Verified end-to-end: a smoke test ran the exporter's YAML
  through `polyparity-php`'s `Validator::compile()` and
  confirmed the seven exercised cases (valid full payload,
  missing required, invalid InSet, invalid Length, invalid
  url, invalid email, int round-trip rejection) match the
  expected polyparity verdicts.

### Cross-language compiled validator (`experiment/cross-lang-validator`, PR #14)

A PHP `RequestDto` compiles to a vanilla ES module that agrees
with `Binder::bind` on the set of failing fields for **0
disagreements over 10,000 random adversarial inputs** across four
fixture DTOs (40,000 inputs / CI run). Useful for PHP shops with
a TypeScript or vanilla-JS frontend that want drift-free
validation across the wire.

#### Added

- **`Rxn\Framework\Codegen\JsValidatorEmitter`** — `emit(string
  $class): string` returns a self-contained ES module mirroring
  `Binder::compileProperty()` line-for-line in JavaScript.
  Coverage: `Required`, `NotBlank`, `Length`, `Min`, `Max`,
  `InSet`, `Email`, `Url` plus the four scalar casts (string, int,
  float, bool). The PHP int round-trip guard
  (`is_numeric($v) && (string)(int)$v === (string)$v`) is mirrored
  bit-for-bit.
- **`Rxn\Framework\Codegen\Testing\ParityHarness`** — generic
  cross-runtime parity harness. `ParityHarness::run($dto, $source,
  $invoke, $iterations)` returns a `ParityResult` with disagreements
  + samples; `ParityHarness::nodeInvoker()` is the standard
  NDJSON-driven Node invocation.
- **`AdversarialInputGenerator`** — DTO-driven random input
  generator. Reflects properties + attributes; emits omissions,
  type mismatches, boundary violations, InSet drift, malformed
  Email/Url fixtures. 20% omit / 8% null / 4% empty string by
  default.
- **`Rxn\Framework\Codegen\Testing\ParityResult`** — outcome
  value object with `describe()` for failure-message formatting.
- **Refusal-on-unknown-attribute.** Emitter throws
  `RuntimeException` for `Pattern`, `Uuid`, `Json`, `Date`,
  `StartsWith`, `EndsWith` and custom `Validates` implementations.
  Each has a known PHP/JS runtime divergence (PCRE vs JS regex,
  parser-shape differences); silent divergence is the worst
  failure mode, so the emitter doesn't try.

#### Tests

- `JsValidatorParityTest` — DataProvider over four fixture DTOs ×
  10K iterations = **40K cross-runtime inputs per CI run**.
  Skipped automatically when `node` isn't on PATH.
- `JsValidatorEdgeCaseTest` — 30 hand-picked tricky inputs.
  Caught a real PHP/JS divergence at `"  42"` for an int field
  (PHP rejected via round-trip; JS had a leading `.trim()` that
  let it through). Fixed by removing the trim — the edge-case
  test caught what 10K random inputs missed.

#### Docs

- `docs/plugin-architecture.md` — what lives in core vs. as
  separate Composer packages. Honest minimal scope: only
  `davidwyly/rxn-orm` is currently extracted; no formal plugin
  contract until there are enough plugins to justify one.
  Documents the rolled-back cross-language ambition (audience
  mismatch + wrong vehicle) explicitly.
- `bench/ab/experiments/2026-05-01-cross-lang-validator.md` —
  full writeup, coverage matrix, and the rolled-back framing.

### Fiber-aware in-request parallelism (`experiment/fiber-await`, PR #8)

Opt-in concurrency primitives for fan-out scenarios (dashboards,
aggregations). Sync-first posture preserved — Fibers are an
explicit per-handler choice, not a framework-wide rewrite.

#### Added

- **`Rxn\Framework\Concurrency\Scheduler`** — Fiber scheduler
  with `await(Promise)` / `awaitAll([Promise])` / `awaitAny([Promise])`.
- **`Rxn\Framework\Concurrency\Promise`** — Fiber-aware promise
  primitive backed by `curl_multi` for HTTP fan-out.
- **`Rxn\Framework\Concurrency\HttpClient`** — Fiber-friendly
  HTTP client (`get`, `post`) returning `Promise`.
- **`Rxn\Framework\Concurrency\await.php`** — function-level
  helpers (`await`, `awaitAll`, `awaitAny`) for handler code.
- **`docs/horizons.md`** — research-directions roadmap. Four
  directions sized with cost / mechanism / ship signal: schema
  as truth taken further, observability ships in the box,
  fiber-aware concurrency (this experiment), profile-guided
  compilation.
- **Example app `/dashboard` route** — fan-out demo using three
  parallel HTTP calls via `awaitAll`.



Five PSR specs landed end-to-end across the dispatch spine, plus
two structural refactors and an opt-in on-disk dump cache. Every
change is behaviour-equivalent (the test suite grew from 265 →
483 / 586 → 1048 assertions, all green) and the example app's
seven golden + edge paths verify clean end-to-end through the new
stack.

#### Added

- **PSR-7 / PSR-15 ingress and middleware contract.** All eight
  shipped middlewares (`BearerAuth`, `Cors`, `ETag`, `Idempotency`,
  `JsonBody`, `Pagination`, `RequestId`, `Transaction`) now
  implement `Psr\Http\Server\MiddlewareInterface`; the `Pipeline`
  is PSR-15-native end-to-end. `PsrAdapter::serverRequestFromGlobals`
  builds a `ServerRequestInterface` from PHP superglobals (with
  `php://input` wrapped lazily); `PsrAdapter::emit` writes a
  `ResponseInterface` to the SAPI. A/B benchmarks showed PSR-7
  ingress winning **9–14% on binder-driven cells** vs the previous
  superglobal path, so it ships as the default for new apps.
- **`App::serve(Router $router, ?callable $invoker = null): void`**
  — static, boot-free PSR-7/15 entry point. Builds the
  `ServerRequest`, threads it through the route's middleware
  pipeline, dispatches the matched handler via the default
  invoker, emits a `ResponseInterface`. Drops the example app's
  front controller from ~25 lines of explicit ingress/Pipeline/emit
  wiring to one call. Convention router (`App::run`,
  `Service\Api`, `/v{N}/{controller}/{action}`) is **fully
  preserved** — `serve()` is a parallel entry point, not a
  replacement.
- **PSR-11 container.** `Rxn\Framework\Container` now `implements
  \Psr\Container\ContainerInterface`; signatures match the spec
  exactly (`get(string $id, array $parameters = []): mixed`,
  `has(string $id): bool`). New `ContainerNotFoundException`
  satisfies `Psr\Container\NotFoundExceptionInterface` for missing
  entries; the broader `ContainerException` already satisfied
  `ContainerExceptionInterface`. Third-party PSR-11 consumers can
  inject the container without an adapter.
- **PSR-3 logger.** `Rxn\Framework\Utility\Logger` now `implements
  \Psr\Log\LoggerInterface` via `Psr\Log\LoggerTrait`; `log()`
  signature widened to `mixed $level, string|\Stringable $message`.
- **PSR-14 event dispatcher.**
  `Rxn\Framework\Event\EventDispatcher` and `ListenerProvider`
  implement the spec; the listener provider does class-hierarchy
  lookup so listeners on parent classes / interfaces fire too.
  Wired into `Idempotency` middleware as the first real consumer:
  optional dispatcher constructor arg, emits
  `IdempotencyHit` on replay and `IdempotencyMiss` on cold-path
  entry. Null dispatcher → no-op via `?->dispatch()`, no overhead.
- **`Rxn\Framework\Codegen\DumpCache`** — opt-in on-disk dump
  cache for compiled PHP closures. Both `Container::compileFactory`
  and `Binder::buildCompiled` go through it: when `DumpCache::useDir($path)`
  is configured, eval'd source is written to `<sha1>.php` and
  `require`'d back instead. opcache treats the files like any
  other PHP source — preload-eligible, shared bytecode across
  workers, shared JIT trace cache. Content-addressed filenames
  give free invalidation; atomic temp-file + rename handles
  concurrent cold-start races.
- **`Binder::bindRequest(string $class, ServerRequestInterface
  $request): RequestDto`** — reads `queryParams` + `parsedBody`
  from PSR-7 directly, falls back to inline JSON-decode of the
  body when `parsedBody` is empty. No dependency on the
  `JsonBody` middleware having mutated `$_POST` first; the
  example app's POST handler binds the DTO straight from the
  request.
- **`Response::problem(int $code, ?string $title, ?string $detail,
  ?array $validationErrors)`** — public RFC 7807 factory used by
  middleware short-circuits.

#### Refactored

- **`Response` is now a property bag.** All previously-public
  fields are private; access is via `getData()`, `getMeta()`,
  `getErrors()`, `getValidationErrors()`, `addMetaField()`. The
  factory methods (`getSuccess`, `getFailure`) build state via
  the accessors instead of populating typed properties via
  reflection. `getErrorCode()` now allow-lists 4xx / 5xx codes
  and returns `int`, not nullable mixed.
- **`App` encapsulates `$api` and `$stats`.** Both are now
  private with `api()` / `stats()` accessors; the constructor
  no longer eagerly resolves `Service\Registry` (which forced a
  database connection during boot — 404s and `/health` checks
  used to depend on MySQL being reachable).
- **`Idempotency::StoredResponse` is PSR-7-shaped.** Stores
  `(status, headers array, body bytes, fingerprint, createdAt)`
  instead of duplicating Rxn's internal Response.

#### Bench harness fix

- **`bench/compare/load.php` rps metric replaced.** The previous
  `count / wall_clock` rps formula was sensitive to brief stalls
  (any tail latency from `Connection: close` socket churn or
  ephemeral port exhaustion under `php -S`) and produced
  Latin-square-shaped outliers that looked like framework
  regressions. Replaced with a median-window rps: bin completions
  into 100ms windows, take the median bin's count. Robust to
  tail-latency artefacts; the median window is what the
  steady-state would converge to absent harness noise. Confirmed
  by reverse-order control runs.

#### Deferred (parts on the shelf, not pursued)

- **Validator dump.** The public `Validator` API accepts callable
  rules; closures can't be serialised to a PHP file, so the
  `eval()` call in `Validator::compile` stays. Documented as the
  explicit boundary.
- **Router combined-regex chunking.** A second attempt
  (numbered-marker groups instead of `(*MARK:rN)`) reproduced the
  April 29 finding: PCRE alternation overhead regresses the
  common case (early-bucket hit) regardless of sentinel mechanism.
  Negative-result confirmation addendum on the existing writeup;
  the bench rig (placeholder-bucket `first_hit` / `last_hit` /
  `miss` cases in `bin/bench`) is kept for future genuine attempts
  (trie, hybrid, JIT-on study).
- **Routing `Binder::bind()` through `compileFor()`** — the
  6.42× win is real but unattached to production code (no
  internal caller of `compileFor`); behaviour parity is locked
  by `BinderMatrixTest`. Held back: making `eval` mandatory for
  binding is its own product decision and needs a constructor
  edge-case fix first. The parts are on the shelf.

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
