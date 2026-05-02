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

### 1.3 Snapshot-tested contracts in CI

**Claim:** PR opens → CI runs `bin/rxn openapi` → diffs against
`openapi.snapshot.json` committed in the repo. Drift fails the
build unless the PR carries an `api-change` label.

**Mechanism:** A 50-LOC GitHub Action + a stable JSON
serialisation of the OpenAPI doc (sorted keys, normalised
whitespace, etc.). Diff is plain text; failures are obvious;
review-label override is the one explicit knob.

**Cost:** Trivial. Maybe 100 LOC including the action and the
serialisation helper.

**Distinctiveness:** Most schema drift is accidental. This is
the cheapest possible governance layer — closer to a linter
than a feature, but **the kind of feature that experienced
engineering teams immediately recognise as load-bearing**.

**Ship signal:** Adopt it on the framework's own CI first.
Catches one real drift in 30 days → ship. Catches zero → the
project doesn't move fast enough to need it; deprioritise.

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

### 2.1 OpenTelemetry spans via PSR-14

**Claim:** Every middleware, route, binder, validator emits an
OpenTelemetry span via a built-in PSR-14 listener. Toggle with
one config flag.

**Mechanism:** Internal events:
- `RequestReceived` (in `Pipeline::run`)
- `MiddlewareEntered` / `MiddlewareExited` (per-middleware,
  injected via the Pipeline)
- `RouteMatched` (after `Router::match`)
- `BinderInvoked` (around `Binder::bindRequest`)
- `ValidationCompleted`
- `HandlerInvoked` (start of the user handler)
- `ResponseEmitted` (at `PsrAdapter::emit`)

A built-in `OpenTelemetryListener` subscribes to all of these,
opens / closes spans, propagates context. Apps that don't
configure an OTel exporter pay a no-op cost (the listener
checks).

**Cost:** ~400 LOC for the events + listener. Zero new
dependencies if the user provides an OTel SDK; the listener
type-hints `Psr\Log\LoggerInterface`-style on the OTel
contracts.

**Distinctiveness:** The five PSRs already provide the bones.
Rxn just has to ship the listener. **No other PHP framework
ships first-party OTel integration that Just Works** — Symfony
needs `OpenTelemetryBundle`, Laravel has third-party packages,
Slim has nothing native.

**Ship signal:** Trace a real request end-to-end through Jaeger
or Honeycomb. If the span tree is readable without further
configuration, ship. If it requires per-app annotation, the
event surface needs more thought first.

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

### 2.3 Trace context propagation by default

**Claim:** Read `traceparent` in, write `traceparent` out;
surface the current trace ID in `Response.meta.trace_id`.

**Mechanism:** A built-in `TraceMiddleware` (PSR-15) reads the
W3C Trace Context header on ingress, propagates it through to
outbound HTTP via a header injector on `Concurrency\HttpClient`,
writes it back on the response. Zero app code.

**Cost:** ~150 LOC.

**Distinctiveness:** Distributed tracing without a single line
of app code. Combined with 2.1 + 2.2, you have a "production-
ready observability" story that few peers can match without a
significant integration project.

**Ship signal:** Verify trace context propagates through a
two-service scenario (Rxn → Rxn) with a real OTel collector
between them. If the spans link cleanly, ship.

---

### Theme 2 composite — the marketing line

If 2.1 + 2.2 + 2.3 ship: *"Rxn is the only PHP API framework
where distributed tracing, Prometheus metrics, and trace
propagation work out of the box without configuration."* That's
a real product position, especially for teams whose ops
organisation owns the framework decision.

---

## Theme 3: Compilation, harder

### 3.1 Profile-guided compilation

**Claim:** Track which DTOs and routes are hot at runtime; dump
compiled hydrators only for those. Cold paths stay on the
runtime walker — opcache doesn't bloat with classes nobody hits.

**Mechanism:** A counter per `bind()` call, persisted to a
small file. After N requests (or on a periodic flush), read the
counters, dump the top-K classes via the existing DumpCache
infrastructure. Cold classes never get dumped.

**Cost:** ~200 LOC + test scaffolding. The DumpCache and
compile-on-demand machinery already exists; this is purely the
measurement + selective-emission layer.

**Distinctiveness:** **No PHP framework I know does this.** It
borders on the kind of optimisation that academic compiler
papers describe but no production runtime ships. The risk is
"too clever to matter" — which is why the bench has to settle
the question.

**Ship signal:** Set up a workload with 100 DTOs where 10 are
hot; bench memory + first-request latency vs. unconditional
dump. If memory drops meaningfully (>30%) and first-request
latency stays stable, ship. If the gain is <10%, it's an
academic win and not worth the complexity.

---

### 3.2 Compile-time route conflict detection

**Claim:** `bin/rxn routes:check` finds overlapping `#[Route]`
patterns at CI time, not at first request.

**Mechanism:** Walk all `#[Route]` attributes, build the route
table, run a pairwise overlap check (`/users/{id}` vs.
`/users/{name}` is ambiguous; `/users/{id}` vs. `/users/me` is
fine because `me` is a literal). Report ambiguous pairs with
file/line.

**Cost:** ~150 LOC. The hard part is the overlap algorithm;
straightforward once specified.

**Distinctiveness:** Most frameworks resolve at first request,
which means a typo in production manifests as "route never
hits" rather than "framework refused to start." This catches it
at CI.

**Ship signal:** Adopt on the framework's own CI; catches one
real conflict in development → ship. Catches zero → optional
feature, not core.

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
