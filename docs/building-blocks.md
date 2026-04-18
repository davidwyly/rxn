# Building blocks

Small, composable primitives that apps stitch together as needed.
Each one fits in a single file and has no runtime dependencies
beyond what the framework already carries.

## `Rxn\Framework\Http\Pipeline` + `Middleware`

Chain cross-cutting concerns around the terminal handler (usually a
controller dispatcher).

```php
use Rxn\Framework\Http\Pipeline;

$response = (new Pipeline())
    ->add($cors)
    ->add($rateLimit)
    ->add($auth)
    ->handle($request, fn ($req) => $controller->dispatch($req));
```

Middleware signature:

```php
public function handle(Request $request, callable $next): Response;
```

Return a `Response` without calling `$next` to short-circuit the
rest of the pipeline (e.g. rate-limit 429, auth 401).

## PSR-7 / PSR-15 bridge

`Rxn\Framework\Http\PsrAdapter` and `Psr15Pipeline` let apps opt
into the PSR middleware ecosystem without giving up the rest of
Rxn.

```php
use Rxn\Framework\Http\PsrAdapter;
use Rxn\Framework\Http\Psr15Pipeline;

$request = PsrAdapter::serverRequestFromGlobals();

$pipeline = (new Psr15Pipeline())
    ->add(new SomePsr15Middleware())       // any psr/http-server-middleware
    ->add(new AnotherPsr15Middleware());

$response = $pipeline->run($request, $controllerHandler);
PsrAdapter::emit($response);
```

`PsrAdapter::factory()` returns Nyholm's PSR-17 factory (which
implements every PSR-17 interface) in case you need to build
requests or responses by hand.

## `Rxn\Framework\Http\Router`

Explicit pattern routing; see [`routing.md`](routing.md).

## `Rxn\Framework\Service\Auth`

Bearer-token resolver. Register a closure in app bootstrap that
maps a token to a principal; call `extractBearer` + `resolve` from
middleware or a controller that needs auth.

```php
$auth = $container->get(\Rxn\Framework\Service\Auth::class);
$auth->setResolver(function (string $token): ?array {
    return $userRepo->findByToken($token);
});

$token = $auth->extractBearer($request->header('Authorization'));
$user  = $auth->resolve($token);
if ($user === null) {
    throw new \Exception('Unauthorized', 401);
}
```

## `Rxn\Framework\Http\Router\Session`

CSRF synchronizer tokens:

```php
Session::token();                   // lazy 32-byte hex
Session::validateToken($submitted); // constant-time compare
```

## `Rxn\Framework\Utility\Validator`

Small rule-based input validator. Keyword rules, `name:arg` rules,
or callables; no reflection, no magic.

```php
Validator::assert(
    $request->getCollector()->getFromRequest(),
    [
        'email' => ['required', 'email'],
        'age'   => ['required', 'int', 'min:18'],
        'role'  => ['in:admin,member,guest'],
        'slug'  => ['regex:/^[a-z0-9-]+$/'],
    ]
);
```

`check()` returns `['field' => ['message', ...]]` for callers that
want to shape the error response themselves. `assert()` throws
`\InvalidArgumentException` with a compact message and is the
normal boundary check inside a controller.

## `Rxn\Framework\Utility\Logger`

Append-only JSON-lines logger with PSR-3-style level helpers.

```php
$log = new Logger('/var/log/rxn/app.log');
$log->info('order.created', ['order_id' => $id, 'user_id' => $user['id']]);
```

## `Rxn\Framework\Utility\RateLimiter`

File-backed fixed-window limiter, locked with `flock`.

```php
$rl = new RateLimiter('/tmp/rxn-rate', limit: 60, window: 60);
if (!$rl->allow($request->clientIp())) {
    throw new \Exception('Too Many Requests', 429);
}
```

Swap for a Redis implementation behind the same surface when
horizontal scaling demands it.

## `Rxn\Framework\Utility\Scheduler`

Interval- or predicate-based in-process scheduler with JSON state
persistence. Drive from cron or a long-running worker.

```php
$s = new Scheduler('/var/lib/rxn/scheduler.json');
$s->every(60, 'purge-query-cache', fn () => $db->clearCache());
$s->at(fn ($now) => (int)date('G', $now) === 3, 'nightly-report', $reportJob);
$s->run();
```

## `Rxn\Framework\Data\Migration`

File-based SQL migrations, tracked in `rxn_migrations`. Files are
applied in lexicographic order; re-runs are idempotent.

```php
(new Migration($database, '/app/db/migrations'))->run();
```

Name files `NNNN_description.sql` for predictable ordering.

## `Rxn\Framework\Data\Chain`

Foreign-key relationship graph built from a `Map`.

```php
$chain = new Chain($map);
foreach ($chain->belongsTo('orders') as $link) {
    // $link->toTable, $link->toColumn
}
foreach ($chain->hasMany('users') as $link) {
    // $link->fromTable, $link->fromColumn
}
```

Links are immutable `Link` value objects derived from
`information_schema` reflection.

## Query-result caching

```php
$database->setCache('/var/cache/rxn/query', ttl: 300);
$database->enableCache();
```

Every read Query hits the filesystem cache first, keyed by
`md5(type|sql|bindings)`. Writes (INSERT/UPDATE/DELETE/DDL) are
never cached.

## `Rxn\Framework\Data\Filecache`

Object caching with atomic writes. Useful for caching anything
reflection-derived so you don't recompute on every request.
