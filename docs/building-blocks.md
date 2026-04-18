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

## ORM / query builder (`davidwyly/rxn-orm`)

Shipped as a separate composer package — see
[davidwyly/rxn-orm](https://github.com/davidwyly/rxn-orm) — and
required transitively by Rxn. `Database::run($builder)` takes any
`Rxn\Orm\Builder\Buildable` (`Query`, `Insert`, `Update`, `Delete`)
and executes it.

### `Rxn\Orm\Builder\Query`

Fluent SELECT query builder for cases where `Record` scaffolding
doesn't reach. `toSql()` materializes to `[$sql, $bindings]`;
`Database::run($query)` executes the result in one call.

```php
$query = (new Query())
    ->select(['u.id', 'u.email'])
    ->from('users', 'u')
    ->leftJoin('orders', 'o.user_id', '=', 'u.id', 'o')
    ->where('u.active', '=', 1)
    ->andWhereIn('u.role', ['admin', 'owner'])
    ->orderBy('u.created_at', 'DESC')
    ->limit(50)
    ->offset(0);

$rows = $database->run($query);
```

Grouped conditions use a closure argument that receives a fresh
`Query`; its where-calls become a parenthesised sub-expression:

```php
$query->where('tenant_id', '=', 7)
      ->andWhere('status', '=', 'active', function (Query $w) {
          $w->orWhere('status', '=', 'trial');
      });
// ... WHERE `tenant_id` = ? AND (`status` = ? OR `status` = ?)
```

Supported methods: `select`, `from`, `join` / `innerJoin` / `leftJoin` /
`rightJoin` / `joinCustom`, `where` / `andWhere` / `orWhere` /
`whereIn` / `whereNotIn` / `whereIsNull` / `whereIsNotNull` (and
`and*` / `or*` variants), `groupBy`, `having`, `orderBy`, `limit`,
`offset`. Operators validated against the `WHERE_OPERATORS`
whitelist (`=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `IN`, `NOT IN`,
`LIKE`, `NOT LIKE`, `BETWEEN`, `REGEXP`, `NOT REGEXP`).

### Raw expressions

`Rxn\Orm\Builder\Raw` is an opt-out marker for SQL fragments the
builder should emit verbatim instead of identifier-escaping. Use it
for aggregates, function calls, and literals:

```php
use Rxn\Orm\Builder\Raw;

$q->select([Raw::of('COUNT(o.id) AS order_count'), 'u.id'])
  ->from('users', 'u')
  ->leftJoin('orders', 'o.user_id', '=', 'u.id', 'o')
  ->groupBy(Raw::of('DATE(o.created_at)'))
  ->orderBy(Raw::of('RAND()'));
```

Contents are not sanitised — don't interpolate user input into a
`Raw`. Accepted in `select()` columns, `groupBy`, `orderBy`, and as
values in `Insert::row()` / `Update::set()`.

### Insert / Update / Delete

Fluent mutation builders that share `Query`'s where-clause API via
the `HasWhere` trait. Each implements `Buildable`; pass one to
`Database::run()` to execute.

```php
// INSERT (single or multi-row; missing columns bind as null)
$database->run(
    (new \Rxn\Orm\Builder\Insert())
        ->into('users')
        ->row(['email' => 'a@example.com', 'role' => 'admin'])
        ->row(['email' => 'b@example.com', 'role' => 'member'])
);

// UPDATE
$database->run(
    (new \Rxn\Orm\Builder\Update())
        ->table('users')
        ->set(['role' => 'admin', 'updated_at' => Raw::of('NOW()')])
        ->where('id', '=', 42)
);

// DELETE (empty WHERE is blocked by default — opt in explicitly)
$database->run(
    (new \Rxn\Orm\Builder\Delete())
        ->from('users')
        ->where('deleted_at', '<', '2025-01-01')
);
```

`Delete::allowEmptyWhere()` enables `DELETE FROM t` without a
`WHERE` clause — it has to be called explicitly so a forgotten
condition can't accidentally wipe the table.

### Upsert (`ON DUPLICATE KEY UPDATE`, MySQL)

```php
$database->run(
    (new Insert())
        ->into('counters')
        ->row(['key' => 'pageviews', 'value' => 1])
        ->onDuplicateKeyUpdate(['value' => Raw::of('value + 1')])
);
// INSERT INTO `counters` (`key`, `value`) VALUES (?, ?)
// ON DUPLICATE KEY UPDATE `value` = value + 1
```

### `RETURNING` (PostgreSQL / SQLite)

All three mutation builders accept `->returning('col', ...)`;
columns are backtick-escaped, or pass `Raw::of(...)` for arbitrary
projections. MySQL will reject the statement — callers are
responsible for knowing their driver supports `RETURNING`.

### Subqueries

Three entry points; every variant merges the subquery's bindings
into the outer query's positional-binding list at call time.

```php
// WHERE col IN (SELECT ...)
$admins = (new Query())->select(['id'])->from('users')->where('role', '=', 'admin');
$q = (new Query())->select()->from('posts')
    ->where('author_id', 'IN', $admins);

// WHERE col = (SELECT ...)  — scalar subquery on the value side
$latest = (new Query())->select([Raw::of('MAX(id)')])->from('orders');
$q = (new Query())->select()->from('orders')->where('id', '=', $latest);

// FROM (SELECT ...) AS alias
$frequent = (new Query())
    ->select(['user_id', Raw::of('COUNT(*) AS order_count')])
    ->from('orders')->groupBy('user_id')->having('COUNT(*) > 5');
$q = (new Query())->select()->from($frequent, 'frequent')
    ->where('order_count', '>', 10);

// (SELECT ...) AS col  as a projected column
$orderCount = (new Query())->select([Raw::of('COUNT(*)')])
    ->from('orders')->where('user_id', '=', Raw::of('u.id'));
$q = (new Query())->select(['u.id'])
    ->selectSubquery($orderCount, 'order_count')
    ->from('users', 'u');
```

`selectSubquery` should be called before outer `where()` / etc
so its placeholders land ahead of later clauses in the
positional-binding list.

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
