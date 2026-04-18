# rxn-orm

Fluent SQL query builder — SELECT / INSERT / UPDATE / DELETE — with
subqueries, upsert (MySQL), `RETURNING` (Postgres / SQLite), and a
`Raw` escape hatch for expressions the identifier escaper can't
safely handle.

Extracted from the [Rxn](https://github.com/davidwyly/rxn) framework
so it can be used standalone on any PDO-backed project.

## Install

```
composer require davidwyly/rxn-orm
```

## Quickstart

```php
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Update;
use Rxn\Orm\Builder\Delete;
use Rxn\Orm\Builder\Raw;

// SELECT
$q = (new Query())
    ->select(['u.id', 'u.email'])
    ->selectSubquery(
        (new Query())
            ->select([Raw::of('COUNT(*)')])
            ->from('orders')
            ->where('user_id', '=', Raw::of('u.id')),
        'order_count'
    )
    ->from('users', 'u')
    ->leftJoin('roles', 'r.id', '=', 'u.role_id', 'r')
    ->where('u.active', '=', 1)
    ->andWhereIn('u.role_id',
        (new Query())->select(['id'])->from('roles')->where('name', 'LIKE', 'admin%'))
    ->orderBy('u.id', 'DESC')
    ->limit(50);

[$sql, $bindings] = $q->toSql();
$rows = $pdo->prepare($sql); $rows->execute($bindings);

// INSERT with upsert
[$sql, $b] = (new Insert())
    ->into('counters')
    ->row(['key' => 'pageviews', 'value' => 1])
    ->onDuplicateKeyUpdate(['value' => Raw::of('value + 1')])
    ->toSql();

// UPDATE
[$sql, $b] = (new Update())
    ->table('users')
    ->set(['role' => 'admin', 'updated_at' => Raw::of('NOW()')])
    ->where('id', '=', 42)
    ->toSql();

// DELETE (empty WHERE blocked by default)
[$sql, $b] = (new Delete())
    ->from('users')
    ->where('deleted_at', '<', '2025-01-01')
    ->toSql();
```

Every builder implements `Rxn\Orm\Builder\Buildable` and returns
`[string $sql, array $bindings]` from `toSql()`. Pipe them into any
PDO-based executor of your choice.

## Features

- Chainable SELECT with INNER / LEFT / RIGHT JOIN, multiple GROUP BY,
  HAVING, ORDER BY, LIMIT, OFFSET.
- WHERE / AND / OR with nested groups via closure arguments.
- Operator whitelist: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `IN`,
  `NOT IN`, `LIKE`, `NOT LIKE`, `BETWEEN`, `REGEXP`, `NOT REGEXP`.
- `whereIsNull`, `whereIsNotNull`, `whereIn` / `whereNotIn` (with
  array or Buildable subquery).
- Subqueries in three positions: `WHERE col IN (SELECT ...)`,
  `FROM (SELECT ...) AS alias`, and `SELECT ... (SELECT ...) AS col`.
- Upsert via `Insert::onDuplicateKeyUpdate`.
- `RETURNING` on Insert / Update / Delete for drivers that support it.
- `Raw::of(...)` escape hatch for aggregates, function calls, and
  literals the identifier escaper can't handle.
- Delete-with-no-WHERE blocked by default; opt in via
  `allowEmptyWhere()`.

## Testing

```
composer install
vendor/bin/phpunit
```

Tests run against an in-memory sqlite PDO; no external database
needed.

## License

MIT — David Wyly.
