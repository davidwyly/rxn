# Quickstart — `examples/quickstart/`

A minimal Rxn app exercising the modern shape end-to-end:
`App::serve(Router)` entry, pattern routing, PSR-15 middleware
chain, DTO binding + attribute validation, RFC 7807 Problem
Details on every failure path.

270 LOC across three files (one DTO, one file-backed JSON repo,
one entry point) plus this README. No database, no Docker — runs
on bare PHP. State persists in `var/quickstart-products.json` at
the repo root (gitignored), with `flock`-protected writes so the
example survives `php-fpm`-style concurrent workers honestly.

## Run

```bash
composer install
php -S 127.0.0.1:9871 -t examples/quickstart/public
```

In another shell, watch the framework's identity show up across
five paths:

### 1. Health check

```bash
curl http://127.0.0.1:9871/health
# → 200 {"data":{"status":"ok","checks":{"repo":{"status":"ok"}}},"meta":{"status":200}}
```

`HealthCheck::register()` registers the route + closure; the
returned `{data, meta}` array becomes the JSON envelope.

### 2. List (no auth)

```bash
curl http://127.0.0.1:9871/products
# → 200 {"data":[]}
# Note: X-Request-Id header injected by the RequestId middleware.
```

### 3. Create (auth required, DTO validation)

```bash
curl -X POST http://127.0.0.1:9871/products \
     -H 'Authorization: Bearer demo' \
     -H 'Content-Type: application/json' \
     -d '{"name":"Widget","price":9,"status":"published"}'
# → 201 {"data":{"id":1,"name":"Widget","price":9,"status":"published"},"meta":{"status":201}}
```

### 4. Validation failure — every error at once

```bash
curl -X POST http://127.0.0.1:9871/products \
     -H 'Authorization: Bearer demo' \
     -H 'Content-Type: application/json' \
     -d '{"name":"","price":-1,"status":"weird"}'
# → 422 application/problem+json
#   {
#     "type":"about:blank",
#     "title":"Unprocessable Entity",
#     "status":422,
#     "errors":[
#       {"field":"name","message":"is required"},
#       {"field":"price","message":"must be >= 0"},
#       {"field":"status","message":"must be one of draft,published,archived"}
#     ]
#   }
```

Every failure surfaces in one response — clients fixing a form
don't round-trip three times to discover three problems.

### 5. Auth failure

```bash
curl -X POST http://127.0.0.1:9871/products \
     -H 'Content-Type: application/json' \
     -d '{"name":"X","price":1}'
# → 401 application/problem+json {"type":"about:blank","title":"Unauthorized","status":401,...}
```

`BearerAuth` short-circuits before the handler runs.

## What this demonstrates

| Framework feature | Where it shows up |
|---|---|
| `App::serve(Router)` boot-free entry | `public/index.php`, last line |
| Pattern routing + typed constraints | `/products/{id:int}` — non-numeric URLs fall through to 404 |
| Per-route middleware | `->middleware($auth, new RequestId())` |
| DTO binding + attribute validation | `Binder::bindRequest(CreateProduct::class, $request)` |
| Schema as truth | `CreateProduct` drives runtime binding, validation, and the OpenAPI generator (run `bin/rxn openapi --ns=Example --root=examples/quickstart`) |
| RFC 7807 default | Every failure is `application/problem+json` |
| File-backed storage swap-in | `ProductRepo` is ~120 LOC of JSON-over-`flock` so the quickstart works without a database; replace with `rxn-orm` for SQL |

## What this *doesn't* demonstrate

- No database. For storage, install
  [`davidwyly/rxn-orm`](https://github.com/davidwyly/rxn-orm) and
  swap `ProductRepo` for a query-builder or ActiveRecord-backed
  implementation.
- No observability. For span trees, install
  [`davidwyly/rxn-observe`](https://github.com/davidwyly/rxn-observe)
  and register `OpenTelemetryListener` on the framework's PSR-14
  event bus.
- No idempotency middleware (the `Idempotency` middleware needs
  a storage backend; out of scope for a single-process quickstart).

## Layout

```
examples/quickstart/
├── README.md           ← this file
├── public/
│   └── index.php       ← entry point, ~80 LOC
└── src/
    ├── CreateProduct.php   ← DTO with validation attributes
    └── ProductRepo.php     ← file-backed JSON repo (flock-protected)
```
