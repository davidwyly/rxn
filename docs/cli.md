# CLI (`bin/rxn`)

Thin shell over the Migration runner and a couple of file
scaffolders. Run with no arguments (or `help`) to list commands.

## `bin/rxn migrate`

Applies every pending migration in `app/migrations/` (or the
directory named by `APP_MIGRATION_DIR`). Prints each filename as it
applies. Safe to re-run — applied files are recorded in the
`rxn_migrations` table.

```
$ bin/rxn migrate
Applied 2 migration(s):
  0001_create_users.sql
  0002_create_orders.sql
```

## `bin/rxn migrate:status`

Lists applied and pending migrations without touching the schema.

```
$ bin/rxn migrate:status
Applied (2):
  [X] 0001_create_users.sql
  [X] 0002_create_orders.sql
Pending (1):
  [ ] 0003_add_orders_index.sql
```

## `bin/rxn make:controller <NsPrefix> <Name> <Version>`

Writes a versioned controller stub to
`app/Http/Controller/v<Version>/<Name>Controller.php`:

```
$ bin/rxn make:controller Acme\\Widget Order 2
Created /home/user/rxn/app/Http/Controller/v2/OrderController.php
```

Refuses to overwrite an existing file (exit 3). `<Version>` must be
an integer (exit 2 otherwise).

## `bin/rxn make:record <NsPrefix> <Name> <table_name>`

Writes a `Record` model stub to `app/Model/v1/<Name>.php`:

```
$ bin/rxn make:record Acme\\Widget Order orders
Created /home/user/rxn/app/Model/v1/Order.php
```

## `bin/rxn openapi`

Walks `app/Http/Controller/v*/` and emits an OpenAPI 3.0 document
describing every `action_v{N}` method it finds. Reflection-driven,
so the spec is always in sync with the code. Writes to stdout by
default; pass `--out=path.json` to write to a file.

```
$ bin/rxn openapi --title="My API" --version=1.2.0 --ns=Acme\\Widget
{
    "openapi": "3.0.3",
    "info": { "title": "My API", "version": "1.2.0" },
    "paths": {
        "/v1.1/orders/show": { "get": { ... } },
        "/v1.2/orders/index": { "get": { ... } }
    },
    "components": { "schemas": { "RxnSuccess": {...}, "RxnError": {...} } }
}
```

Flags:

| Flag | Purpose |
|---|---|
| `--ns=<NS>` | App PSR-4 prefix. Defaults to `APP_NAMESPACE` from `.env`. |
| `--title=<T>` | `info.title`. Default: `Rxn API`. |
| `--version=<V>` | `info.version`. Default: `0.1.0`. |
| `--out=<PATH>` | Write to this file instead of stdout. |
| `--root=<DIR>` | Alternative project root to scan (testing / CI). |

Parameter mapping:

- Scalar parameters (`int`, `string`, `bool`, …) → query parameters.
- A parameter whose type implements
  `Rxn\Framework\Http\Binding\RequestDto` → `requestBody` under
  `application/json`, and the operation method flips from `GET`
  to `POST`. The DTO's public properties + validation attributes
  emit as a JSON Schema under `#/components/schemas/{ShortName}`:
  - `#[Required]` or non-default non-nullable → `required` list
  - `#[Min(n)]` / `#[Max(n)]` → `minimum` / `maximum`
  - `#[Length(min, max)]` → `minLength` / `maxLength`
  - `#[Pattern('/.../')]` → `pattern` (delimiters stripped)
  - `#[InSet([...])]` → `enum`
  - Nullable properties → `nullable: true`
  - Default values → `default`
- DI-injected object params (everything else) are skipped.

Because the generator reflects the *same* metadata the Binder
uses at runtime, the spec can't drift from the validation
behaviour. Ship one source of truth (the DTO) and both sides
stay in lockstep.

Pipe the output into Swagger UI, Redocly, or any OpenAPI
consumer — or pair with `Http\OpenApi\SwaggerUi::html($specUrl)`
for one-line interactive docs.

## Environment knobs

| Variable | Purpose |
|---|---|
| `APP_CLI_DATABASE` | Which `DATABASE_<NAME>_*` profile to use. Default: `write`. |
| `APP_MIGRATION_DIR` | Migration directory. Default: `<project>/app/migrations`. |
| `APP_NAMESPACE` | App PSR-4 prefix; used by `openapi` when `--ns` is omitted. |
| `RXN_CLI_OUTPUT_ROOT` | Where `make:*` writes files. Default: project root. Useful for tests. |
