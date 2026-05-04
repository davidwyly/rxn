# Contributing to Rxn

Rxn is a small JSON API framework. Five motives drive every
decision — **novelty, simplicity, interoperability, speed, strict
JSON** — in that rough order of weight. Every change should make the
framework smaller or clearer, or add the smallest thing that makes
a real-world app easier to build.

## Operating principles

1. **Minimalism beats flexibility.** Default to the simplest
   implementation that solves the concrete problem in front of you.
   Three similar lines are better than a premature abstraction. Do
   not add factories, strategies, configuration, or interfaces for a
   single implementation.
2. **One file, one idea.** A new feature should live in one small
   file when possible. If it grows past ~150 lines, reconsider the
   design before adding more.
3. **No new runtime dependencies without a clear reason.** The current
   runtime deps are narrow and earned their place: `vlucas/phpdotenv`
   (`.env` loader), `nyholm/psr7` + `nyholm/psr7-server` + `psr/http-server-middleware`
   (the PSR-7/PSR-15 bridge is opt-in but these are cheap), and
   `davidwyly/rxn-orm` (the extracted query builder). Resist
   adding more. Prefer standard PHP, PDO, and what is already in
   `src/Rxn`.
4. **Fail loud, fast, and specific.** Throw a typed exception from
   `Rxn\Framework\Error` with a descriptive message. Never swallow
   errors or return `false` for an exceptional condition.
5. **Boundaries are the only place to validate.** Validate input at
   the Collector / Controller / Service boundary. Trust internal code
   and framework invariants once data is past that line.
6. **Bind, never concatenate.** All user values flow into SQL through
   PDO bindings. Table and column names come from schema reflection,
   never from request data.
7. **Keep the request path fast.** Do not add work to every request
   (logging, hashing, reflection) unless the cost is negligible.
   Cache reflection results when they are used on the hot path.
8. **If it isn't covered by a test, it doesn't count as done.** Add a
   small phpunit test for every non-trivial behavior you change or
   introduce.

## Before you add a feature

Ask, in order:
- Can the caller do this in app code? If yes, do not add it to the framework.
- Is there already a class that does almost this? Extend it rather
  than adding a sibling.
- Can this be a plain function or a 30-line class? Prefer that.
- Does the README advertise a feature that is still a stub? Prefer
  finishing that over adding something new.

Avoid:
- New abstraction layers ("manager", "factory", "registry",
  "provider") when one concrete class would do.
- Configuration knobs without a real caller that needs them.
- Helpers that wrap one stdlib call.
- Refactoring for taste alone; keep diffs focused on the task.

## Project layout

- `public/index.php` — single entrypoint; creates `App` and runs it.
- `app/` — sample application (sample controllers, models, env).
- `src/Rxn/Framework/` — the framework itself.
  - `Startup.php` — defines constants, registers autoloader, loads
    `.env`, instantiates databases.
  - `App.php` — request/response orchestration.
  - `Container.php` — PSR-4-style DI with autowiring + cycle
    detection. Read this before adding any "service" plumbing.
  - `Http/` — request pipeline, session, response rendering.
  - `Data/` — PDO-based DB layer, query cache, filecache, migrations.
  - `Model/` — `Record` base class that underpins scaffolded CRUD.
  - `Error/` — one exception type per subsystem.
- `src/Rxn/Orm/` — query builder used for non-scaffolded queries
  (still evolving; the parser work is in progress).
- `docker/` — local development stack. PHP 8.3-fpm, nginx 1.27,
  mysql 8.0. Xdebug is opt-in via `INSTALL_XDEBUG=1`.
- `.github/workflows/ci.yml` — lint + phpunit matrix (PHP 8.1–8.4).

## Key conventions

- **Namespaces track the filesystem.** `Rxn\Framework\Foo\Bar` lives
  at `src/Rxn/Framework/Foo/Bar.php`.
- **Framework exceptions extend `Rxn\Framework\Error\AppException`.**
  Pick the closest subsystem type (`DatabaseException`,
  `QueryException`, `RequestException`, …) or add a new subclass
  before reaching for `\Exception`.
- **All container resolutions are singletons.** Classes resolved
  through `Container::get()` are cached on first resolution; the
  same instance comes back on every subsequent call.
- **Secrets come from `.env`.** Never hardcode credentials in
  source or fixtures.

## Running things

```
composer install            # install dev deps
vendor/bin/phpunit          # run the test suite
composer validate --strict  # sanity-check composer.json
bin/rxn help                # list CLI subcommands
bin/bench                   # microbenchmark the building blocks
```

PHP lint every touched file with `php -l <path>` before committing.

## Testing checklist

- Add or update a test under `src/Rxn/Orm/Tests/` or a new sibling
  `Tests/` directory for non-ORM code. Register new suites in
  `phpunit.xml`.
- Prefer pure unit tests over integration. The Container, Query
  builder, Collector, and Response layers can all be exercised
  without a database.
- Do not rely on network or filesystem state outside `sys_get_temp_dir()`.

## Building blocks you can compose

- **`Rxn\Framework\Utility\Validator`** — rule-based boundary
  validator. `Validator::assert($payload, $rules)` throws on
  failure; `check()` returns structured errors. Supports keyword
  rules (`required`, `email`, `int`, ...), `name:arg` rules
  (`min:18`, `in:a,b,c`, `regex:/.../`) and callables.
- **`Rxn\Framework\Utility\Logger`** — append-only JSON-lines logger.
  `new Logger('/var/log/rxn/app.log'); $log->info('msg', [ctx])`.
- **`Rxn\Framework\Utility\RateLimiter`** — fixed-window, file-backed.
  `new RateLimiter('/tmp/rate', limit: 60, window: 60);
  if (!$rl->allow($ip)) { return 429; }`.
- **`Rxn\Framework\Http\Middleware\BearerAuth`** — Bearer-token
  middleware. Construct with a `callable(string): ?array` resolver
  that maps a token to a principal; the middleware enforces
  Authorization-header presence and short-circuits to 401
  Problem Details on rejection.
- **`Rxn\Framework\Utility\Scheduler`** — interval / predicate-based
  task scheduler with JSON persistence; drive from cron or a worker.
- **HTTP middleware pipeline (`Rxn\Framework\Http\Pipeline` +
  `Middleware`)** — compose cross-cutting concerns (rate limiting,
  CSRF, auth, logging) without touching every controller. Build a
  `Pipeline`, `->add()` your middlewares, then call
  `->handle($request, $terminal)` where `$terminal` is the controller
  dispatcher. Middleware may short-circuit by returning a Response
  without calling `$next`.
- **PSR-7 / PSR-15 bridge (`Rxn\Framework\Http\PsrAdapter` +
  `Psr15Pipeline`)** — `PsrAdapter::serverRequestFromGlobals()`
  builds a PSR-7 `ServerRequest` from PHP's globals (via
  nyholm/psr7-server); `PsrAdapter::emit()` streams a PSR-7
  `Response` back to the SAPI. `Psr15Pipeline` runs a chain of
  `Psr\Http\Server\MiddlewareInterface` around a
  `RequestHandlerInterface`, so any ecosystem middleware (CORS,
  OAuth, sessions, tracing, ...) drops in unchanged. Use the
  Rxn-native `Pipeline` for Rxn-shaped middleware, or this one for
  PSR-15 interop.
- **Explicit router (`Rxn\Framework\Http\Router`)** — method + path
  pattern matching with `{name}` placeholders, alongside the
  convention-based URL scheme. `$router->get('/products/{id}', ...);
  $router->match($method, $path)` returns `['handler' => ...,
  'params' => ['id' => '42'], 'pattern' => ...]` or null.
  `hasMethodMismatch()` tells you whether a 405 is warranted.
  Handlers are opaque — the caller decides how to invoke them
  (container, pipeline, etc.).

When finishing any of these, prefer the smallest working version.
Ship it, get tests green, move on.
