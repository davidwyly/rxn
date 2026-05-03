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

## `bin/rxn openapi:check`

CI gate that catches schema drift on PR open. Regenerates the
OpenAPI spec the same way `bin/rxn openapi` does, then diffs it
against `openapi.snapshot.json` (or whatever path
`--snapshot=PATH` names) committed in the repo. Exits with one
of three codes so CI policy can decide what to do:

| Exit | Meaning |
|---|---|
| `0` | No drift. |
| `1` | Additive changes only (new operations, new optional fields, loosened constraints). Or breaking changes when `--allow-breaking` is passed. |
| `2` | Breaking changes detected and not opted-in. |

Seed the snapshot with `--update`:

```
$ bin/rxn openapi:check --update
Updated snapshot at /path/to/repo/openapi.snapshot.json
```

Then commit the file. On the next PR that changes a DTO, the
gate runs:

```
$ bin/rxn openapi:check
Breaking changes (2):
  [breaking] components.schemas.CreateProduct.properties.price — maximum tightened from 1000000 to 100000
  [breaking] paths./v1.1/products/show.parameters.query.id — parameter became required
Additive changes (1):
  [additive] components.schemas.Product.properties.thumbnail_url — optional property added

If the changes are intentional, refresh the snapshot:
  bin/rxn openapi:check --update
```

Flags:

| Flag | Purpose |
|---|---|
| `--snapshot=<PATH>` | Snapshot file path. Default: `openapi.snapshot.json` in the project root. |
| `--update` | Overwrite the snapshot with the current spec; exits 0. |
| `--allow-breaking` | Downgrade exit `2` to exit `1` (the diff still prints). For PRs explicitly authorised to break the contract — e.g. behind a `breaking-change` review label. |
| `--title=<T>` | `info.title` for the regenerated spec. Default: `Rxn API`. Match this to the value used in your `bin/rxn openapi --update` flow so the snapshot doesn't drift on metadata. |
| `--version=<V>` | `info.version` for the regenerated spec. Default: `0.1.0`. Same matching caveat as `--title`. |
| `--ns=<NS>` | App PSR-4 prefix. Defaults to `APP_NAMESPACE`. |
| `--root=<DIR>` | Alternative project root to scan. |

Classification rules (mirrors `Codegen\Snapshot\OpenApiSnapshot::diff`):

- **Operations / paths**: removed → BREAKING, added → ADDITIVE.
- **Parameters** (keyed by `(name, in)` — query `id` and header
  `id` are different parameters): removed or made required →
  BREAKING; new required → BREAKING; new optional → ADDITIVE;
  type changed → BREAKING.
- **Schema properties**: removed → BREAKING (conservative — the
  snapshot doesn't track request- vs response-side ref usage);
  new required → BREAKING; new optional → ADDITIVE; type changed
  → BREAKING; became required → BREAKING.
- **Numeric / set constraints** (`minimum`, `maximum`,
  `minLength`, `maxLength`, `enum`): tightening → BREAKING;
  loosening → ADDITIVE.
- **Opaque scalars** (`pattern`, `format`): first-time appearance
  or any change → BREAKING (regex/format identity is hard to
  reason about); removal → ADDITIVE.
- **`nullable`**: any flip → BREAKING in either direction. The
  snapshot doesn't know whether a referenced schema is reached
  via a request body or a response, and one of those breaks in
  each direction (request: nullable→non-nullable rejects nulls
  the client used to send; response: non-nullable→nullable lets
  through nulls the client may not handle). Conservative.
- **`default`**: ADDITIVE either way (defaults document intent;
  the wire contract isn't bound by them).

Pairs naturally with `JsValidatorEmitter` (PHP↔JS validator
parity) and `PolyparityExporter` (cross-language YAML spec):
three downstream artifacts from one `RequestDto` source of
truth, three drift-detection mechanisms in one repo.

## `bin/rxn routes:check`

CI gate that catches ambiguous `#[Route]` patterns before they
ship. Reflects every `#[Route]` attribute across the discovered
controllers and runs a pairwise overlap check; ambiguous pairs
are reported with both source files + line numbers.

```
$ bin/rxn routes:check --ns=Acme\\Widget
Found 1 route conflict(s):

Ambiguous routes (every segment overlaps):
  - GET /items/{id:int}    (Acme\Widget\Http\Controller\v1\ItemsController::showById() at .../ItemsController.php:8)
  - GET /items/{slug:slug} (Acme\Widget\Http\Controller\v1\ItemsController::showBySlug() at .../ItemsController.php:11)

Each pair is a runtime-silent ambiguity — whichever route
was registered first wins; the other is dead code.
```

Exit codes:

| Exit | Meaning |
|---|---|
| `0` | No conflicts. |
| `1` | One or more conflicts found (each printed with file/line). |

Flags:

| Flag | Purpose |
|---|---|
| `--ns=<NS>` | App PSR-4 prefix. Defaults to `APP_NAMESPACE`. |
| `--root=<DIR>` | Alternative project root to scan. |

What counts as a conflict: two routes with overlapping methods
AND patterns that share at least one URL both could match.
The detector uses a static compatibility matrix derived from
the constraint regexes — for the standard types Rxn ships:

| Pair | Overlap? | Why |
|---|---|---|
| `int` ∩ `slug` | yes | `slug = [a-z0-9-]+` accepts digit-only strings |
| `int` ∩ `alpha` | no | digits vs letters — disjoint |
| `int` ∩ `uuid` | no | uuid requires hyphens; int rejects them |
| `slug` ∩ `uuid` | yes | lowercase hex + dashes are slug-legal |
| `alpha` ∩ `uuid` | no | uuid contains digits; alpha rejects them |
| `any` ∩ * | yes | `[^/]+` is the universe |
| `<custom>` ∩ * | yes (conservative) | unknown regex; flag-on-doubt for the gate |

Static-vs-dynamic case: `/users/me` vs `/users/{name:T}` is a
conflict iff the literal `me` matches the regex behind type
`T`. So `/users/me` overlaps with `{name:any}`, `{name:slug}`,
`{name:alpha}` (all accept letters); does NOT overlap with
`{id:int}` (digits-only) or `{id:uuid}` (length-mismatched).

What it correctly does NOT flag:

- Different verbs on the same path (`GET` and `POST` on
  `/users/{id}` are distinct dispatch targets, never
  ambiguous).
- Disjoint segment counts (`/x/{id}` vs `/x/{id}/orders`).
- Disjoint constraint character sets (`int` vs `alpha`).

## Environment knobs

| Variable | Purpose |
|---|---|
| `APP_CLI_DATABASE` | Which `DATABASE_<NAME>_*` profile to use. Default: `write`. |
| `APP_MIGRATION_DIR` | Migration directory. Default: `<project>/app/migrations`. |
| `APP_NAMESPACE` | App PSR-4 prefix; used by `openapi` when `--ns` is omitted. |
| `RXN_CLI_OUTPUT_ROOT` | Where `make:*` writes files. Default: project root. Useful for tests. |
