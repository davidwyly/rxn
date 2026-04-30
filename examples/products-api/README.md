# products-api — worked example

A tiny Products CRUD API built on Rxn. Eight files, four routes,
five framework features in flight. The point of this example is to
land the **schema-as-truth, multiple consumers** principle from
[`docs/design-philosophy.md`](../../docs/design-philosophy.md) — and
to show the full middleware stack composing cleanly.

## Run it

```sh
# From the repository root:
php -S 127.0.0.1:9871 -t examples/products-api/public

# In another shell:
curl http://127.0.0.1:9871/health
```

No `composer install` in this directory — the example reuses the
framework's own `vendor/` (the front controller `require`s
`../../../vendor/autoload.php`). Just make sure you've run
`composer install` at the repo root.

The first request creates `examples/products-api/var/products.sqlite`
and `examples/products-api/var/idempotency/` — both git-ignored.
Delete `var/` to reset.

## What's demonstrated

### Schema as a single source of truth

[`app/Dto/CreateProduct.php`](app/Dto/CreateProduct.php) is one PHP
class with public typed properties and validation attributes. **Four
framework consumers read from it:**

1. **`Binder::bind`** — hydrates the DTO from the request body,
   casting strings to the declared types
2. **Validation** — runs `#[Required]`, `#[NotBlank]`, `#[Length]`,
   `#[Min]`, `#[InSet]`, `#[Url]` against the cast values
3. **`Binder::compileFor`** — emits a 6.4× compiled fast path for
   long-lived workers (RoadRunner / Swoole / FrankenPHP)
4. **`Http\OpenApi\Generator`** — emits the request-body schema for
   the OpenAPI spec straight from the same reflection

Adding a property to `CreateProduct` simultaneously updates input
binding, validation, the spec, and the compiled hot path with no
config to touch.

### Five middlewares cooperating

The pipeline ([`public/index.php`](public/index.php)) wires up:

| Middleware | What it does |
|---|---|
| **`HealthCheck`** route helper | `GET /health` — runs configured liveness checks, returns `{data: {status, checks}, meta: {status}}` |
| **`Pagination`** | Parses `?limit=&offset=` or `?page=&per_page=` into a typed value object; emits `X-Total-Count` + RFC 8288 `Link` headers from `meta.total` |
| **`BearerAuth`** | `Authorization: Bearer <token>` enforcement; resolves principal via the existing `Service\Auth`; 401 on miss |
| **`Idempotency`** | `Idempotency-Key` header replay (Stripe-shape): cold → process+store, replay → same response with `Idempotent-Replayed: true`, mismatched body → 400, in-flight retry → 409 |

### Try every path

```sh
# 1. Health check
curl http://127.0.0.1:9871/health

# 2. List with pagination
curl -i "http://127.0.0.1:9871/products?per_page=5"
#   ↑ X-Total-Count: 0 (empty)

# 3. POST without auth → 401
curl -i -X POST http://127.0.0.1:9871/products \
     -H 'Content-Type: application/json' -d '{}'

# 4. POST with auth + idempotency key → 201
curl -X POST http://127.0.0.1:9871/products \
     -H 'Authorization: Bearer demo-admin' \
     -H 'Idempotency-Key: my-key-1' \
     -H 'Content-Type: application/json' \
     -d '{"name":"Widget","price":9.99,"status":"published","homepage":"https://example.com"}'

# 5. POST replay (same key, same body) → 201, Idempotent-Replayed: true
curl -i -X POST http://127.0.0.1:9871/products \
     -H 'Authorization: Bearer demo-admin' \
     -H 'Idempotency-Key: my-key-1' \
     -H 'Content-Type: application/json' \
     -d '{"name":"Widget","price":9.99,"status":"published","homepage":"https://example.com"}'

# 6. POST replay with different body → 400
#    "idempotency_key_in_use_with_different_body"
curl -i -X POST http://127.0.0.1:9871/products \
     -H 'Authorization: Bearer demo-admin' \
     -H 'Idempotency-Key: my-key-1' \
     -H 'Content-Type: application/json' \
     -d '{"name":"DIFFERENT","price":9.99}'

# 7. Validation failure — every field error collected at once → 422
curl -X POST http://127.0.0.1:9871/products \
     -H 'Authorization: Bearer demo-admin' \
     -H 'Idempotency-Key: my-key-2' \
     -H 'Content-Type: application/json' \
     -d '{"name":"","price":-1,"homepage":"not-a-url"}'
```

### Auth tokens

Two demo tokens are wired up in `public/index.php`:

| Token | Principal |
|---|---|
| `demo-admin` | `{id: 1, name: "Admin", role: "admin"}` |
| `demo-viewer` | `{id: 2, name: "Viewer", role: "viewer"}` |

Real apps swap the resolver closure for a database / JWT verifier
/ OAuth introspector — the rest of the framework doesn't change.

## File map

```
examples/products-api/
├── README.md                  ← you are here
├── .gitignore                 ← excludes var/
├── public/
│   └── index.php              ← front controller, pipeline assembly,
│                                 dispatcher (~70 lines of glue)
├── app/
│   ├── Dto/
│   │   └── CreateProduct.php  ← typed DTO with attributes — the
│   │                            single source of truth
│   └── Repo/
│       └── ProductRepo.php    ← minimal SQLite-backed repository
└── var/                       ← runtime data (git-ignored)
    ├── products.sqlite
    └── idempotency/           ← FileIdempotencyStore data
```

That's the whole example. ~250 lines of PHP outside the framework.
