# Design philosophy

> Fast, readable, and small are usually thought of as a trilemma —
> you pick two. They aren't. The trilemma is real *only when you
> treat the three as orthogonal axes to optimise independently.*
> Bad design hurts all three at once; good design helps all three
> at once. This document is the working theory of how Rxn lands all
> three by leaning on a small set of principles that compound.

## 1. Opinionated narrowing is a force multiplier

Slim and Symfony are flexible because they can't decide what users
want: HTML or JSON, sessions or stateless, sync or async, one
router or three. Every undecided question becomes a config knob,
an abstraction, and a learning step.

Rxn decides:

- JSON in, JSON out — including errors (RFC 7807 problem details).
- No content negotiation. No `Accept`-header branching.
- No view layer, no template engine, no asset pipeline.
- One Pipeline, one Router, one Container, one Binder, one
  Validator. No "DI Container A vs B vs C" decisions.

Each "no" eliminates a category of code, docs, and mental load.
The decision *is* the feature. Our 22-rule Validator is plenty
because exotic constraints go through `fn ($v, $f): ?string`.
Symfony has 80 constraints because they couldn't make that call.

**Cost saved:** every removed feature is a feature you don't
have to learn, document, test, or maintain. **Speed gained:**
removing a content-negotiation phase removes that phase from every
single request.

## 2. Schema as a single source of truth, multiple consumers

```php
final class CreateProduct implements RequestDto {
    #[Required] #[Length(min: 1, max: 100)]
    public string $name;

    #[Required] #[Min(0)]
    public float $price;
}
```

This one declaration drives *four* consumers:

1. **Binder** — hydrates `$dto = Binder::bind(CreateProduct::class)`
   from the request bag, casting strings to typed properties.
2. **Validator** — runs `#[Required]`, `#[Length]`, `#[Min]` against
   the cast values; collects errors; throws `ValidationException`.
3. **OpenAPI generator** — emits the schema portion of the spec
   directly from the property types and attributes.
4. **Compiled fast path** — `Binder::compileFor($class)` reads the
   same reflection and emits straight-line PHP with the per-property
   work inlined (6.4× speedup on this DTO shape).

In Slim / Laravel / Symfony those are typically three or four
separate libraries with three or four config formats. Adding a
property in Rxn automatically updates all four consumers, because
they all start from the same PHP class.

This is the structural reason `Binder::compileFor` exists at all
— the schema was already there. The compile pass is just
*another consumer* of the same source of truth.

## 3. The fast path and the readable path are the same code

```php
$errors = Validator::check($payload, $rules);   // readable, runtime
$check  = Validator::compile($rules);           // 2.45× faster
$errors = $check($payload);                     // same shape, same errors
```

The user's mental model doesn't bifurcate. They learn `check()`.
When they need the speedup, they swap one line. **No separate
"fast mode" framework, no flag toggle, no hidden behavior.** Same
API surface, two performance profiles.

Symfony's compiled container is conceptually the same idea but
costs a build step + a `var/cache/` directory + a `bin/console
cache:clear` command. Ours is a method call that returns a
closure. **The optimisation is opt-in, but the API is identical
in shape.**

The same pattern shows up in `Container` (transparent caches,
opt-in compiled factories), `Router` (transparent verb buckets +
static hashmap, no opt-in needed), and `Binder` (`bind()` runtime,
`compileFor()` compiled). One mental model carries.

## 4. Compile away PHP overhead, call C at the leaves

The single most useful negative result this branch produced was
**CompiledJson** — a Fastify-style schema-compiled JSON encoder
that lost 35-43% to plain `json_encode`. The lesson:

> **Schema-compiling user-space code only beats the baseline when
> the baseline is also user-space.**

PHP's `json_encode` is implemented in C. User-space PHP — even
eval'd, even with type info baked in — can't catch it.

`Validator::compile` and `Binder::compileFor` won (+2.45× and
+6.4×) because the runtime baselines for both are pure-PHP
dispatch overhead: rule parsing, switch dispatch, helper-call
frames, `ReflectionAttribute::newInstance()` per property per
call. Compiling those away compounds to large multipliers
*because the C-level work is already at the leaves and runs at
the same rate either way*.

Articulated as a rule: **schema-compile what's in PHP; never
try to outrun a C extension from PHP.** That rule is what saved
us from spending more time chasing a CompiledJson v2.

## 5. Common case at zero cost; explicit escape hatches

The shape: pick the path that needs the *least* configuration for
the most common job, and provide an escape hatch when the
defaults stop fitting.

For routing, that has shifted with the framework's identity. The
modern default — and what the README and `examples/products-api`
lead with — is **explicit `Http\Router`**, populated either
programmatically or from `#[Route]` attributes via
`Http\Attribute\Scanner`. The headline "FastAPI-class DTO +
attribute routing + reflection-driven OpenAPI" pitch wants the
attribute path front and centre:

```php
final class ProductController {
    #[Route('GET', '/products/{id:int}')]
    #[Middleware(BearerAuth::class)]
    public function show(int $id): array { ... }
}
```

A **convention router** also ships (`/v{N}/{controller}/{action}/...`
parsed from `$_GET` into `App::run`). It's older than the
explicit Router and is preserved for apps that already depend on
it, but it isn't the recommended path for new code — attribute /
explicit routing matches the framework's marketing identity and
the example app.

The same "common case at zero cost" pattern shows up across the
framework:

- **Container** — autowiring is the default; `bind($abstract, $concrete)`
  is the escape hatch for interfaces / factories.
- **Validator** — keyword rules cover 95% of cases; callables
  (`fn ($v, $f): ?string`) cover the rest.
- **Binder** — public typed properties + `#[Required]` cover most
  DTOs; custom `Validates` attributes cover the rest.
- **Pipeline** — `add($middleware)` is the API; the PSR-15 stack
  (`Psr15Pipeline`) is the escape hatch when you need ecosystem
  middleware (see [`psr-7-interop.md`](psr-7-interop.md)).

**Ergonomics for the common case, escape hatches for the rare.**

## 6. Measure to commit; document negative results

Every shipped optimisation has an A/B run with worktree-based
comparison, ranges, and a non-overlapping-range verdict. Nothing
ships on a hunch.

Negative-result branches stay on origin with their writeups —
`bench/ab-router-combined-alternation` (regressed common case),
`bench/ab-pipeline-no-array-reverse` (noise), `bench/ab-psr-adapter-factory-cache`
(noise), `bench/ab-compiled-json-encoder` (the most useful failure
the branch produced). Future contributors can see what was tried
and why it didn't ship.

This produces two compounding effects:
- A culture that **rejects performance theatre.** Anything that
  doesn't move the needle stays in a branch and a writeup, not
  in main.
- A growing **library of generalisable lessons.** "Don't try to
  out-PHP a C extension" is now a sentence in this document
  because we paid for it once.

The methodology lives in `bench/ab/README.md`. The driver lives
at `bench/ab.php`. The cumulative scoreboard lives at
`bench/ab/CONSOLIDATION.md`.

## 7. Stack optimisations transparently; expose them via APIs only when there's a real tradeoff

Five Container experiments stacked transparently — reflection
cache, plan cache, parsed-name cache, hot-path trim, compiled
factories. **Zero API change** across all five. Existing code that
calls `$container->get($class)` got 2.2× faster without
recompiling, reconfiguring, or even knowing.

The compile-path opt-ins (`Validator::compile`, `Binder::compileFor`)
are visible APIs because they have a real tradeoff: under
PHP-FPM where state resets per request, the compile cost is paid
every request and the compiled path is *break-even or slightly
slower*. Under RoadRunner / Swoole / FrankenPHP where workers
persist, the compile cost amortises and the compiled path wins
2-6×.

That tradeoff doesn't fit behind an automatic toggle. So we made
it a method call. The user picks the deployment shape; the
framework respects it.

**Rule of thumb:** if the optimisation is a free lunch, hide it.
If it has a tradeoff, name it.

## 8. Aggressive smallness as a design constraint

The framework has a budget. Every new feature has to either
displace an existing feature or be obviously load-bearing.
Cors / RequestId / JsonBody / ETag / RateLimiter / basic Auth
are the floor — anything below that goes in app code or a plugin.

Smallness compounds. A 500-line Container is readable in one
sitting; a 5,000-line Container needs documentation explaining
itself. A small framework is **debuggable from inside** — when
something goes wrong, the user reads the source instead of
filing an issue. That speed counts.

## 9. Ergonomics ARE performance

Cognitive load is a real cost. A framework that's too opinionated
to read is slow to *develop in*. Time-to-first-bug-fixed is a
real metric.

Rxn fits in your head:
- The framework is ~10k LOC of PHP excluding tests; the dispatch
  spine — Container, Router, Pipeline, Binder, Validator,
  Request, Response — is ~3k of that, readable in one sitting.
  Middlewares, OpenAPI, scaffolding, and the legacy ActiveRecord
  layer make up the rest and don't sit on the hot path.
- One mental model (request → router → pipeline → controller →
  response) covers the request lifecycle.
- One error envelope (RFC 7807) covers every failure mode.
- One DTO declaration drives binding, validation, OpenAPI,
  and compiled hot paths.

When the user is debugging a 422 response, the path from the
problem JSON back to the `#[Required]` attribute that fired is
straight through. They don't grep through bundle config; they
read four files.

## 10. Honesty about tradeoffs

The README says **alpha**. The benchmarks docs note that
`App::run()` has open bugs. The compile-path docs say *don't use
this under FPM unless you understand it*. The cross-framework
comparison harness states that `php -S` is a development server
and absolute numbers don't transfer to FPM-behind-nginx.

Don't oversell. Don't pretend RoadRunner-only wins are
FPM-friendly. Don't pretend a complex feature is simple. Don't
hide the rough edges.

This honesty is **how you keep the design budget intact**. A
framework that pretends to do everything ends up doing
everything, which means it does nothing well.

## 11. Optional dependencies via lazy-autoload, not hard requires

Some optional integrations want a real package — `psr/simple-cache`
for Redis-via-Idempotency, `davidwyly/rxn-orm` for the query builder
+ ActiveRecord layer. The naive design lists them in `require` and
forces every Rxn app to install them. The naive *alternative* is
duck-typing (`object` parameters + `method_exists` validation), which
works but costs the reviewer mental load on every read.

There's a third path that gets all three of *no required deps*,
*full nominal-type interop*, and *no clever-with-a-cost code*:

```php
use Psr\SimpleCache\CacheInterface;            // namespace alias only
use Rxn\Orm\Builder\Buildable;

final class Psr16IdempotencyStore implements IdempotencyStore {
    public function __construct(private readonly CacheInterface $cache) {}
}

class Database {
    public function run(\Rxn\Orm\Builder\Buildable $builder) { ... }
}
```

PHP's autoloader is **lazy on typed parameters.** A `use` statement
is a namespace alias — PHP doesn't try to resolve it at file-parse
time. Resolution happens only when the symbol is actually
*referenced at runtime*: a `new`, an `instanceof`, or a method
call where PHP needs to verify an argument's type. So:

- The framework as a whole loads cleanly without the optional
  package installed — files containing the type-hint sit inert,
  never referenced, never autoloaded.
- Anyone calling `new Psr16IdempotencyStore($cache)` is passing a
  PSR-16 cache, which means they have `psr/simple-cache` installed.
  Autoload resolves cleanly at construction time.
- Anyone calling `Database::run($buildable)` has rxn-orm
  installed. Same shape.
- Reviewers see normal nominal type-hints. No `object` weirdness,
  no `method_exists` validation, no docblock gymnastics.

The pattern in `composer.json`:

```json
"require": { /* what every app needs */ },
"require-dev": {
    "davidwyly/rxn-orm": "^0.1"     // framework's own tests use it
},
"suggest": {
    "psr/simple-cache": "...required only when using Psr16IdempotencyStore...",
    "davidwyly/rxn-orm": "...required only when you use Database::run() or ActiveRecord..."
}
```

The `suggest` block isn't decorative — it documents the exact code
paths that need each optional package, so apps know when to reach
for `composer require`.

This pattern is now load-bearing for two distinct integrations
(PSR-16 cache, Rxn ORM). It scales: any future ecosystem
integration that's optional for some apps but wants a clean
nominal type-hint goes through the same shape. **Document the
trick once, apply it N times.**

## The recursive insight

These principles compound:

- Every "kept narrow" decision (1, 8) lets the next decision be
  cleaner.
- Every "schema as truth" use (2) lets the next consumer be
  faster to write.
- Every "measure to commit" verdict (6) steers the *next*
  optimisation toward signal-rich targets.
- Every "transparent stack vs explicit opt-in" call (7) lets the
  user keep one mental model while the framework gets faster
  under their feet.
- Every honest README sentence (10) keeps the budget intact for
  the next feature decision.

The framework gets *easier and faster together as it grows*, not
by trading them off. That's the answer to the trilemma: it isn't
a trilemma if the axes share a substrate, and these ten
principles are how we keep the substrate healthy.

---

## Where each principle shows up in the code

| Principle | Concrete example |
|---|---|
| 1 — opinionated narrowing | `Response::__construct` produces JSON; `App::run` rolls every uncaught exception into RFC 7807; no Accept header branch anywhere |
| 2 — schema as truth | `Binding\RequestDto` + `#[Required]` etc. → consumed by `Binder`, `Validator`, `OpenApi\Generator`, `Binder::compileFor` |
| 3 — same code, two profiles | `Validator::check` / `Validator::compile`; `Binder::bind` / `Binder::compileFor`; `Container::get` (transparent) |
| 4 — schema-compile PHP, not C | `bench/ab/experiments/2026-04-29-compiled-json-encoder.md` — negative result that produced the rule |
| 5 — convention + escape hatch | `App::run` convention router → `Http\Router` explicit; `Container` autowire → `bind()` |
| 6 — measure to commit | `bench/ab.php` driver, 16 experiment writeups, 4 negative-result branches preserved on origin |
| 7 — transparent vs visible opt-in | Container's 5 stacked caches: zero API change. `Validator::compile`: visible because of the FPM tradeoff |
| 8 — smallness as constraint | ~10k LOC framework, ~3k LOC dispatch spine (Container, Router, Pipeline, Binder, Validator, Request, Response) — readable in one sitting; the rest is opt-in subsystems |
| 9 — ergonomics are performance | `bin/preload.php`, `bench/ab/CONSOLIDATION.md`, `docs/index.md`'s topic table |
| 10 — honesty | "alpha" status in README, `App::run` open-bugs note in `docs/benchmarks.md`, the long-lived-worker caveat in every compile-path docstring |
| 11 — optional deps via lazy autoload | `Psr16IdempotencyStore` (typehints `\Psr\SimpleCache\CacheInterface`) and `Database::run()` / `ActiveRecord` (typehint `\Rxn\Orm\Builder\Buildable` / `Query`); both packages live in `composer.json`'s `suggest` block, framework loads cleanly without them |

## Per-request state is sync-only by design

`BearerAuth::current()` and `RequestId::current()` publish state
through a `private static ?T $current` slot, set on `handle()`
entry and cleared in `finally`. This is correct under any
**synchronous** dispatch — including Swoole's default I/O-hooked
fibers, where request handlers don't `Fiber::suspend()` between
the set and the clear, so the static is observed only by the
running request's own downstream code.

The shape is deliberate. The README's stated position is *"sync-
first, process-per-request, predictable. We deliberately don't
chase async."* Adding a per-fiber context map (one of the
"obvious" robustness changes) would import shape the framework
explicitly opts out of, for a hazard that only triggers when a
handler holds principal across an explicit `Fiber::suspend()` —
not a usage pattern the framework endorses or supports.

If you have a handler that explicitly suspends a fiber while
holding state, propagate that state yourself; don't rely on
`current()`. This is the same boundary `rxn-orm` draws with
`Record::clearConnections()` for fiber-isolated connection
binding.

## Anti-patterns we deliberately avoid

These are decisions where the easy choice would have hurt the
trilemma. Naming them so we don't drift back into them:

- **Plugin escalator.** Don't ship a plugin for every imaginable
  use case. Slim has plugins for Auth, CSRF, Sessions, OAuth,
  Rate Limit, Cache, Mail, View, Twig, Doctrine. Each one is a
  surface to learn, document, version, and break. Rxn's middleware
  set is small and stays small.
- **Magic that you can't read.** No `__call`, no
  `Reflection::invoke` chains, no proxies, no AOP. If a method
  is dispatched, the user can step into it.
- **Service locators.** The Container is autowire + `bind()`. No
  `app()` helper, no `resolve()` global, no facade.
- **Lifecycle hooks for hypothetical needs.** No
  `before/around/after` hook framework. If you need to do
  something around a request, write a middleware. There's
  exactly one extension point.
- **Backward compatibility for vapour.** Alpha-stage means
  breaking changes are allowed. We don't carry vestigial APIs to
  preserve a hypothetical user.
- **Premature configuration.** Every config option is a UX cost.
  When something has a default, the default is opinionated and
  the user only learns the option when they need to override it.
