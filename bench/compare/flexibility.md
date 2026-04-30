# Flexibility matrix

What each framework actually ships **out of the box**, against the
features that matter for an opinionated JSON-first API. Companion
to the throughput numbers in [`README.md`](README.md). Plug-ins
and bridge packages don't count — this is what you get from a
clean install and a `composer require`.

`[X]` = built into core, no extra dependencies. `[~]` = available
but requires an extra package or non-trivial wiring. `[ ]` = not
shipped; build it yourself.

| Feature | Rxn | Slim 4 | Symfony micro | Raw PHP |
|---|---|---|---|---|
| **Routing — typed constraints** (`{id:int}`, `{slug:slug}`, `{id:uuid}`, custom) | [X] | [~] regex inline only | [~] requirements per route | [ ] |
| **Routing — attribute-based** (`#[Route(...)]` on the method) | [X] | [ ] | [~] sensio/framework-extra-bundle | [ ] |
| **Routing — named routes + URL generator** | [X] | [X] | [X] | [ ] |
| **Routing — nested groups w/ shared prefix + middleware** | [X] | [X] | [X] | [ ] |
| **Method mismatch reporting** (`hasMethodMismatch` for 405) | [X] | [X] | [X] | [ ] |
| **Typed DTO request binding** (hydrate + cast a class from request) | [X] | [ ] | [ ] | [ ] |
| **Attribute-driven validation** (`#[Required]`, `#[Min]`, `#[Length]`, `#[Pattern]`, `#[InSet]`) | [X] | [ ] | [~] symfony/validator (separate) | [ ] |
| **Aggregated field errors** — every failure surfaces in one response | [X] | [ ] | [~] (with the validator package) | [ ] |
| **RFC 7807 Problem Details** as the error shape | [X] | [ ] | [ ] | [ ] |
| **Uncaught exceptions auto-wrapped** as JSON / Problem Details | [X] | [~] errorMiddleware | [~] error_listener | [ ] |
| **Production stack-trace stripping** out of the box | [X] | [~] config flag | [~] env-driven | [ ] |
| **OpenAPI 3 spec generation from controllers** | [X] `bin/rxn openapi` | [ ] | [ ] | [ ] |
| **Swagger UI helper** (one-line interactive docs) | [X] `SwaggerUi::html()` | [ ] | [ ] | [ ] |
| **DI container w/ autowiring** | [X] | [~] PHP-DI / pimple | [X] | [ ] |
| **DI — interface-to-implementation binding** | [X] `$c->bind()` | [~] (in chosen container) | [X] | [ ] |
| **DI — circular-dependency detection** | [X] | [~] | [X] | [ ] |
| **In-process HTTP test client** (no web server, no curl) | [X] `Testing\TestClient` | [~] (build it on PSR-7) | [X] (`KernelBrowser`) | [ ] |
| **Fluent response assertions** integrated with PHPUnit | [X] `TestResponse` | [ ] | [X] | [ ] |
| **PSR-7 / PSR-15 bridge** so ecosystem middleware drops in | [X] `PsrAdapter` + `Psr15Pipeline` | [X] (PSR-7 native) | [~] PSR-7 bridge package | [ ] |
| **Query builder** (SELECT + JOIN + WHERE + subqueries + upsert) | [X] `davidwyly/rxn-orm` (auto-required) | [ ] | [~] doctrine/dbal | [ ] |
| **ActiveRecord** (hydrate, hasMany / hasOne / belongsTo) | [X] | [ ] | [~] doctrine/orm | [ ] |
| **Scaffolded CRUD against a live schema** | [X] `Record` + `CrudController` | [ ] | [~] api-platform | [ ] |
| **Database migrations** (timestamped `*.sql` runner) | [X] | [ ] | [~] doctrine/migrations | [ ] |
| **CSRF synchronizer tokens** (constant-time compare) | [X] `Session::token()` | [ ] | [~] symfony/security-csrf | [ ] |
| **Bearer-token auth scaffold** (extract + verify hooks) | [X] `Service\Auth` | [ ] | [~] symfony/security | [ ] |
| **Rate limiting** (file-backed, `flock`-protected) | [X] `Utility\RateLimiter` | [ ] | [~] symfony/rate-limiter | [ ] |
| **CORS w/ preflight** | [X] `Http\Middleware\Cors` | [ ] | [~] nelmio/cors-bundle | [ ] |
| **Request-id correlation middleware** | [X] | [ ] | [ ] | [ ] |
| **JSON body decoding w/ size cap** | [X] | [~] BodyParsing | [X] (`Request::getContent`) | [ ] |
| **Conditional GET — weak ETags + 304 short-circuit** | [X] `Http\Middleware\ETag` | [ ] | [~] HttpKernel listener | [ ] |
| **Filesystem-backed query cache** (atomic writes, TTL) | [X] | [ ] | [~] symfony/cache | [ ] |
| **Object file cache** (atomic writes for reflection-derived data) | [X] `Data\Filecache` | [ ] | [~] symfony/cache | [ ] |
| **Scheduler** (interval / predicate based) | [X] `Utility\Scheduler` | [ ] | [~] symfony/scheduler | [ ] |
| **JSON-lines event logger** | [X] `Utility\Logger` | [ ] | [~] monolog | [ ] |
| **CLI scaffolding** (`make:controller`, `make:record`, etc.) | [X] `bin/rxn` | [ ] | [~] symfony/console | [ ] |

## How to read this

The honest version of this matrix: Slim and Rxn are *micro*
frameworks; full Symfony is not. The "Symfony micro" column is the
bare HttpKernel + Routing combination shown in
`apps/symfony/public/index.php` — i.e., the smallest thing that's
still recognizably Symfony — *not* `symfony/framework-bundle` with
Doctrine + Twig + the validator. If you assemble the full Symfony
+ bundles stack, almost every `[~]` here flips to `[X]`, but
you're paying for it: bigger vendor tree, slower bootstrap, more
config to know.

The interesting comparison is **Rxn vs Slim**: similarly sized,
similar deployment story, similar throughput ceiling. Rxn's pitch
is that an opinionated JSON-only stack can ship more of the
"things every JSON API ends up writing" — Problem Details, DTO
binding, OpenAPI generation, ETag short-circuit, in-process test
client — without growing past Slim's surface area.

## Caveats

- The `[~]` cells are author judgement calls about what counts as
  "shipped". A maintainer of a competing framework can reasonably
  argue some of them up to `[X]` if the bridge package is part of
  their official starter template. PRs welcome.
- Feature richness ≠ better fit. If you don't want a framework
  with opinions about your error envelope, Slim is correct for
  you and Rxn is wrong for you.
- This compares against framework cores. Application starters
  built on top of any of these (e.g., a Slim-based skeleton with
  pre-wired logging + auth + cache) close most of the gaps.
