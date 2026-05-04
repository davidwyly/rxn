# Horizons — directions that could break Rxn out

This document captures research directions that, if pursued, would
reposition the framework from "small, fast, opinionated PHP API
framework" to something genuinely distinctive in its class.

It is **not** a roadmap with dates. Each theme is a hypothesis
about what would change the framework's competitive story, sized
roughly, with the mechanism named explicitly. Read it with the
same eye you'd bring to a `bench/ab/experiments/` writeup —
ideas earn the right to ship by hitting their decision criteria,
not by being interesting.

The fiber-await experiment
([`bench/ab/experiments/2026-05-01-fiber-await.md`](../bench/ab/experiments/2026-05-01-fiber-await.md))
is the proof point that this exercise produces real results: the
hypothesis was 3× speedup on a 3-call fan-out, the bench was
exactly 3.00×, and the implementation is ~340 LOC of opt-in
framework code. The other themes below are similarly concrete —
what's missing is the build-and-bench step, not the design.

## Why this doc exists

Rxn already has technical wins (PSR-15-native, schema-compiled
binder, RFC 7807 default, bench numbers that beat its peers).
Those are correct but not novel — Slim and Mezzio could do most
of it with effort. What would put Rxn into the conversation as
**the framework you adopt because of capabilities no one else
has** is a different category of investment.

Four themes group the directions worth thinking about:

1. **Schema as truth, taken further** — the DTO already powers
   binding + validation + OpenAPI + the compiled hydrator. What
   if it powered six more things?
2. **Observability ships in the box** — most frameworks make you
   bolt on OpenTelemetry + Prometheus + structured logging from
   three different libraries. Rxn already has PSR-14 wired; the
   listeners are a few hundred LOC each.
3. **Compilation, harder** — schema-compiled fast paths are
   Rxn's distinctive runtime feature. There are levers below the
   ones already pulled.
4. **Speculative** — Fibers landed (3× win on fan-out). The
   speculative pile has more in it.

Each direction below has the same shape: one-sentence claim,
mechanism, cost estimate, distinctiveness check, ship signal.

---

## Theme 1: Schema as truth, taken further

The unifying argument: **the DTO declaration is executable in
multiple targets**. Today: bind, validate, OpenAPI, compile.
Tomorrow, six more.

### 1.1 Multi-target client generation

**Claim:** `bin/rxn client typescript`, `bin/rxn client python`,
`bin/rxn client go` generate type-safe HTTP clients in arbitrary
languages from the framework's own OpenAPI output.

**Mechanism:** The OpenAPI generator already exists. Wrap a few
common targets — TypeScript types + fetch wrapper, Python
pydantic + httpx, Go structs + net/http. Each target is a
separate code-gen package; the framework just provides the spec.

**Cost:** ~500 LOC per target language. The TypeScript target is
the highest-leverage (every JSON API has a frontend). Python
second. Go is rarer in API-consumer roles but a good polyglot
demonstration.

**Distinctiveness:** No PHP framework I know of generates
ergonomic clients in non-PHP languages. Symfony's API Platform
gets close but ships its own SDK shape; Slim/Mezzio require
external `zircote/swagger-php` + ad-hoc client tooling.

**Ship signal:** Generate a TypeScript client for the
products-api example, write 50 lines of frontend code that uses
it, verify both sides compile + work end-to-end. If the
ergonomics survive that test, it ships. If the generated client
needs hand-fixing or has rough edges, the spec needs a richer
representation first.

**The repositioning move:** Reframe Rxn from "a PHP framework"
to "**a JSON contract authority that happens to be in PHP**."
The contract is the product; the implementation is the substrate.

---

### 1.2 OpenAPI examples → live test fixtures

**Claim:** `bin/rxn spec:run` reads OpenAPI operation `examples`,
hits the live server with each one, asserts response shape
matches the schema. Integration tests written in YAML, not PHP.

**Mechanism:** Walk the spec, for each operation:
- Build a request from the example (path params + query +
  request body)
- Call the live server (or `TestClient` in-process)
- Validate response status against the operation's responses
  map
- Validate response body against the schema (JSON Schema
  validator)

**Cost:** ~300 LOC. JSON Schema validation is the long pole;
either ship a small validator (200 LOC for the subset Rxn emits)
or pull in `justinrainbow/json-schema` as a dev dependency.

**Distinctiveness:** Closes the loop the OpenAPI gen opens.
Every framework generates specs *from* code; few execute the
spec back *against* the code. With Rxn's schema-as-truth
principle, the spec is verifiable infrastructure, not stale
documentation.

**Ship signal:** Run it against `examples/products-api`, get
zero false positives, catch one real drift on a deliberately-
broken commit. Decision criterion: contract failures must be
unambiguous and the YAML examples must be readable as
intent-stating tests.

---

### 1.3 Snapshot-tested contracts in CI — REALIZED

See `Rxn\Framework\Codegen\Snapshot\OpenApiSnapshot` and the
`bin/rxn openapi:check` subcommand. The classifier walks paths
+ components.schemas and buckets changes as breaking
(operation/parameter/property removals, required-toggles to
required, type changes) or additive (new operations, new
optional fields). The CLI gates breaking changes via three
exit codes: 0 = clean, 1 = additive only or `--allow-breaking`
override, 2 = breaking detected.

**Cost reality:** Came in at ~250 LOC of framework code + 24
tests, modestly above the 100-LOC estimate but well within
"trivial." The estimate undercounted the classifier surface
(every operation/parameter/property/schema axis got its own
rule).

**Status:** Working classifier + CLI + test suite. Adoption on
Rxn's own CI is the next step — once the framework's own
`openapi.snapshot.json` is committed, the 30-day "catches one
real drift" criterion can run.

**Why it shipped before adoption-criterion was met:** the
horizons doc's criterion is for keeping the feature, not
shipping it. Implementation cost was low enough that the cost
of *not* having the gate while running the experiment exceeded
the cost of the gate itself.

---

### 1.4 Reverse direction: OpenAPI → scaffold

**Claim:** `bin/rxn scaffold:from-spec products.openapi.yaml`
writes RequestDto / ResponseDto / Controller skeletons matching
the spec's operations.

**Mechanism:** Walk the OpenAPI doc, for each operation:
- Generate a `RequestDto` from the requestBody schema (with
  `#[Required]`, `#[Min]`, `#[Length]`, etc. mapped from
  JSON Schema keywords)
- Generate a `ResponseDto` from the 2xx response schema
- Generate a controller method stub with the route attribute,
  parameter type-hints, and a TODO body

**Cost:** ~400 LOC. The reverse mapping (JSON Schema → PHP
attributes) is symmetric to the existing OpenAPI generator's
forward mapping, so half the table already exists.

**Distinctiveness:** "Adopt Rxn for an existing API" workflow.
A team handed a spec by a design partner can run one command
and have running scaffolding in the framework's idioms. Slim
and Mezzio need third-party tools (Swagger Codegen) which
generate generic PHP, not Rxn-shaped PHP.

**Ship signal:** Round-trip a non-trivial OpenAPI doc (Stripe-
style, 10+ endpoints) and verify the generated code compiles
without modification. If the spec round-trips cleanly,
schema-as-truth is genuinely bidirectional and the marketing
claim holds end-to-end.

---

### 1.5 DTO version migrations

**Claim:** Declare `CreateProductV1` and `CreateProductV2`;
framework generates the migrator with explicit hooks for fields
that changed.

**Mechanism:** Reflect both DTOs, diff their property graphs,
produce a `MigratorV1ToV2` class with:
- Identical fields auto-mapped
- Renamed fields stubbed with a `// TODO: from $name1` comment
- Removed fields deleted with a comment naming the source
- Added fields stubbed with a `// TODO: from where?` comment
- Type-changed fields stubbed with a cast-or-throw block

**Cost:** ~250 LOC. Real APIs version eventually; nobody
automates this; every team builds a worse version themselves.

**Distinctiveness:** Schema-as-truth applied to time, not just
output targets. The DTO graph isn't just the contract for one
release — it's the contract across releases.

**Ship signal:** Use it in the framework itself when DTO names
change. If the generated migrator's TODOs are reasonable enough
that filling them in is mechanical, ship.

---

## Theme 2: Observability ships in the box

The framework already has PSR-14 wired ([`Rxn\Framework\Event\EventDispatcher`](../src/Rxn/Framework/Event/EventDispatcher.php)).
Most frameworks make you bolt on OpenTelemetry + Prometheus +
structured logging from three different libraries.

### 2.0 Observability event surface — REALIZED

See `Rxn\Framework\Observability\Events` (static dispatch slot)
and `Rxn\Framework\Observability\Event\*` — eight first-party
events: `RequestReceived` / `ResponseEmitted` (paired by id),
`MiddlewareEntered` / `MiddlewareExited` (per-middleware brackets,
exit fires even on throw), `RouteMatched` (template + params),
`BinderInvoked` (with `path` tag for compile-cache hit ratio),
`ValidationCompleted` (failures grouped by field name),
`HandlerInvoked` (entered/exited brackets). Apps subscribe a
single listener on the `FrameworkEvent` marker interface and
receive the lot.

**Cost reality:** ~250 LOC of framework code + 27 tests
(8 Events helper + 5 Pipeline + 4 Router + 4 Binder + 6
App::serve). No new dependencies — leans on the existing
PSR-14 dispatcher / provider. Per-emit cost without a
dispatcher installed is one bool read (`Events::enabled()`);
with a no-op listener, ~50 ns.

**Status:** Shipped as the substrate for 2.1 (OTel) and 2.2
(Prometheus). Both are listeners over the same channel; this
layer makes them thin shims rather than full instrumentation
projects.

---

### 2.1 OpenTelemetry spans via PSR-14 — REALIZED

Lives in the [`davidwyly/rxn-observe`](https://github.com/davidwyly/rxn-observe)
plugin, not in core. The plugin's `Rxn\Observe\OpenTelemetryListener`
subscribes to the framework's `FrameworkEvent` interface (theme 2.0)
and translates each event into the matching OTel call: root request
span, per-middleware children, handler span, route metadata, binder
+ validation as span events.

**Why a plugin, not core:** A first-party OTel listener inside core
would have pulled `open-telemetry/api` (and effectively `sdk`) into
every Rxn install — including apps that don't need distributed
tracing. The plugin pattern matches `davidwyly/rxn-orm`: core stays
lean, the integration ships separately, the framework's `composer
suggest` block documents how to opt in.

**Cost reality:** ~200 LOC of listener code + 9 integration tests
in the plugin repo. The framework-side cost (theme 2.0) was the
event surface itself; the listener is a thin shim on top.

**Status:** Shipped. Span tree forms correctly against the OTel
SDK + `InMemoryExporter` in the plugin's test suite. The Jaeger /
Honeycomb walkthrough is a follow-up validation step — the same
listener feeds any OTLP-speaking collector via whichever exporter
the app installs.

**Distinctiveness still holds:** No PHP framework ships
first-party OTel integration that Just Works. `rxn-observe` is the
listener; Rxn core's event surface is the integration point.

---

### 2.2 Prometheus metrics from PSR-14 events

**Claim:** Every framework event auto-exports a Prometheus
counter / histogram / gauge.

**Mechanism:** A `MetricsListener` subscribes to the same events
as 2.1, increments named counters:
- `rxn_requests_total{method, path, status}`
- `rxn_request_duration_seconds{method, path}` (histogram)
- `rxn_idempotency_hits_total{key_prefix}` (already wired —
  `IdempotencyHit` event exists)
- `rxn_idempotency_misses_total{key_prefix}`
- `rxn_validation_failures_total{dto, field}`
- `rxn_binder_compile_total{dto}` (compile-cache misses)

Expose via a `/metrics` endpoint that emits the standard
Prometheus exposition format.

**Cost:** ~300 LOC. The exposition format is text; no library
needed.

**Distinctiveness:** The events already exist for other reasons.
Metrics is the cheapest possible additional consumer.

**Ship signal:** Run the framework under Prometheus + Grafana
for a week, verify the dashboards make sense. If Idempotency
hit-rate, validation failure rate, and request duration tell a
coherent operational story, ship.

---

### 2.3 Trace context propagation by default — REALIZED

See `Rxn\Framework\Http\Tracing\TraceContext` (W3C-compliant
value object) and `Rxn\Framework\Http\Middleware\TraceContext`
(PSR-15 ingress + response echo). Outbound propagation is
wired into `Concurrency\HttpClient` via `applyTraceContext()`:
when the request-scoped context is set, every outbound call
gets `traceparent` (with a freshly-advanced parent-id, per
spec) and `tracestate` (verbatim). Caller-supplied headers
win — apps that explicitly thread their own context aren't
overridden, including case-variant headers (`Traceparent`).

**Cost reality:** ~250 LOC of framework code + 26 tests. The
W3C spec validation surface (version forward-compat, all-zero
sentinels, hex normalisation, oversized tracestate handling,
flag bits) added scope beyond the 150-LOC sketch but kept the
implementation honestly compliant.

**Status:** Working middleware + value object + HttpClient
hook + test suite. Adoption: pipeline composers add
`new TraceContext()` to their middleware list and outbound
propagation is automatic. The next-step bench (Rxn → Rxn with
a real OTel collector) is gated on theme 2.1 (OTel spans via
PSR-14) producing actual spans for the collector to receive.

This unlocks the rest of theme 2 — every span emitted by 2.1
needs a trace-id, and trace-id propagation is the
prerequisite for the spans-form-a-tree story.

---

### Theme 2 composite — the marketing line

If 2.1 + 2.2 + 2.3 ship: *"Rxn is the only PHP API framework
where distributed tracing, Prometheus metrics, and trace
propagation work out of the box without configuration."* That's
a real product position, especially for teams whose ops
organisation owns the framework decision.

---

## Theme 3: Compilation, harder

### 3.1 Profile-guided compilation — REALIZED

See `Rxn\Framework\Codegen\Profile\BindProfile` (in-memory hit
counter + atomic JSON persistence), `Binder::warmFromProfile()`
(load + selective compile of top-K), and `bin/rxn dump:hot`
(CLI bridge: read profile → compile top-K via DumpCache).

The runtime hook is one line in `Binder::bind()`:
`BindProfile::record($class)` — ~50 ns per call, negligible on
the hot path. `bind()` also auto-dispatches to the in-memory
compiled cache when present, so the speedup actually lands at
runtime once a worker has called `warmFromProfile()` on boot.

**Cost reality:** ~200 LOC of framework code + 22 tests (12
profile, 6 binder integration, 4 CLI), plus the bench harness
(~270 LOC). On target with the estimate. The DumpCache +
`compileFor()` infrastructure already existed; this PR was the
measurement + selection layer the horizons doc described.

**Status:** Shipped. The bench step from the ship signal —
100 DTOs, 10 hot, three modes (runtime-only, unconditional,
profile-guided) — landed alongside the feature; see
[`bench/ab/experiments/2026-05-03-profile-guided-compilation.md`](../bench/ab/experiments/2026-05-03-profile-guided-compilation.md).
50% memory saving over unconditional dump (target: >30%) with
hot-path throughput within measurement noise of unconditional
(4.64× vs 4.72× over the runtime walker). Ship signal met.

Apps wire it as: post-deploy step runs `bin/rxn dump:hot
--profile=… --top=20` to pre-populate the cache, and the
bootstrap calls `Binder::warmFromProfile($path, $top)` so
every worker starts with the in-memory compiled cache loaded.
Cold classes stay on the runtime walker; opcache memory pays
only for hot DTOs.

---

### 3.2 Compile-time route conflict detection — REALIZED

See `Rxn\Framework\Http\Routing\ConflictDetector` and the
`bin/rxn routes:check` subcommand. The detector reflects every
`#[Route]` attribute across the discovered controllers, runs a
pairwise overlap check using a constraint-type compatibility
matrix (`int`, `slug`, `alpha`, `uuid`, `any`, plus a
conservative-overlap fallback for custom types), and reports
each ambiguous pair with both source files + line numbers.
Exit 0 = clean, 1 = conflicts found.

**Cost reality:** Came in at ~250 LOC of framework code + 19
tests, modestly above the 150-LOC estimate (the constraint-
overlap matrix and the static-vs-dynamic case both grew the
algorithm beyond the initial pairwise-equality sketch).

**Status:** Working detector + CLI + test suite. Adoption on
Rxn's own CI is the next step. Catches the realistic
ambiguities — `/items/{id:int}` vs `/items/{slug:slug}` (slug
accepts digits), `/users/me` vs `/users/{name:any}` (any
accepts everything), `/x/{a:hash}` (custom type) vs anything
(conservative). Correctly does NOT flag genuine non-conflicts:
disjoint methods (`GET` vs `POST` on the same path), disjoint
constraint character sets (`int` vs `alpha`), different segment
counts.

---

## Theme 4: Speculative

### 4.1 Fiber-aware middleware bridge — REALIZED

See [`bench/ab/experiments/2026-05-01-fiber-await.md`](../bench/ab/experiments/2026-05-01-fiber-await.md)
and PR #8. Hypothesis: 3× speedup on a 3-call fan-out via
in-request fibers + curl_multi. Result: exactly 3.00× at
fanout=3, exactly 5.98× at fanout=6, sub-millisecond scheduler
overhead, ~340 LOC, zero new dependencies.

**Status:** Working prototype + bench + demo. Decision criteria
all met. Pending review.

This entry exists in the doc to **make explicit that the other
themes are the same shape of bet**: a hypothesis you can prove
or disprove with ~few hundred LOC and a real bench.

### 4.2 Event-sourced replay debugging

**Claim:** Every PSR-14 event the framework emits is logged in
order. Combined with the Idempotency machinery's stored
request/response shape, you can replay any production request
from the captured event stream.

**Mechanism:** A `ReplayLogListener` writes the full event
stream + the original `ServerRequest` to a JSONL log per
request, scoped by `traceparent`. `bin/rxn replay <trace_id>`
reads the log, reconstructs the `ServerRequest`, runs it through
a fresh framework instance, asserts the response matches what
was captured.

The stretch version: *time-travel debugging*. Pause execution
at any logged event, inspect framework state, step forward.
Probably overreach for v1; the basic replay is the practical
shape.

**Cost:** ~500 LOC for basic replay. Time-travel is open-ended.

**Distinctiveness:** Production incident debugging is dominated
by "we can't reproduce it locally." If the framework can replay
a captured production request bit-for-bit, that whole class of
debugging session changes shape.

**Risk:** Storage cost (every request → log entry). Privacy
implications (request bodies may contain PII). Both real
operational concerns.

**Ship signal:** Use it to debug one real bug that previously
required guess-and-check. If the workflow is meaningfully
faster than `tail -f /var/log/rxn.log + grep`, ship.

---

## How these compose

The themes aren't independent. **Doing all of Theme 1** turns
Rxn into a JSON contract authority — the spec is the product,
the framework implements it, and clients in arbitrary languages
fall out for free. **Doing all of Theme 2** makes Rxn the PHP
framework with the lowest operational ceremony for production-
grade observability — a real consideration for the
ops-conservative shops that historically picked Symfony or
Laravel out of caution.

**Doing both** is the strongest pitch:

> *Declare your DTOs once. Get type-safe clients in 4 languages
> for free. Ship to production with distributed tracing,
> metrics, and structured logs working out of the box, no
> configuration required. Replay any production request locally
> for debugging. Beat Slim by 50% and Symfony by 30% on the
> bench. All in 11K LOC of framework code.*

That's not the marketing pitch *today* — most of those bullets
are aspirational. But every one of them is **mechanism, not
magic**. Each theme has a defensible cost estimate and a clear
ship signal. The fiber experiment is the proof point that
following this template produces real results.

## What this doc is not

- **A roadmap with dates.** Nothing here has a commit timeline.
  Pursuing these is a decision, not a schedule.
- **A wish list.** Each direction has cost, mechanism, and a
  ship-or-don't-ship criterion. Cut any of them when the
  criterion fails.
- **Marketing.** Don't repeat any claim above to a user until
  the bench or demo backs it. The fiber experiment was claimed,
  built, benched, and verified before the README touched it.

## What this doc *is*

- A snapshot of the directions the framework could move in if
  the maintainer wanted to invest there.
- A demonstration that Rxn's existing primitives (PSR-14
  events, DumpCache, schema-as-truth, OpenAPI generator,
  Concurrency package) are levers that compound — each new
  consumer of those primitives is one fewer thing to engineer
  from scratch.
- An honest catalogue of what's hard, what's risky, and what's
  more about engineering than insight.

If even half of these ship over the next year, Rxn has a
genuinely distinctive position in the PHP API framework class.
If none of them ship, the framework as it stands today is
already the densest, fastest, most opinionated thing in its
size class. **Either way is a defensible product.**
