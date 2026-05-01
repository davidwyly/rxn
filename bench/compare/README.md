# Cross-framework comparison harness

A pure-PHP harness that benchmarks Rxn against Slim 4, a hand-wired
Symfony micro-kernel, and a raw-PHP baseline — all running on
`php -S` (the built-in CLI server), driven by an in-process curl_multi
load generator. No Docker, no `wrk`.

The harness lives in `bench/compare/`:

```
bench/compare/
├── apps/
│   ├── rxn/public/index.php       # Router + Binder, native ingress
│   ├── rxn-psr7/public/index.php  # same Router + Binder, PSR-7/15 ingress
│   ├── slim/{composer.json, public/index.php}
│   ├── symfony/{composer.json, public/index.php}
│   └── raw/public/index.php       # baseline, no framework
├── load.php                       # curl_multi load generator
├── run.php                        # driver: install → boot → load → report
├── flexibility.md                 # feature matrix
└── results/                       # gitignored; markdown reports go here
```

`rxn` and `rxn-psr7` are the same framework with the same Router
and the same Binder — they differ only at ingress and egress.
`rxn` reads `$_SERVER` / `php://input` directly and emits with
`echo` + `header()`; `rxn-psr7` builds a PSR-7 ServerRequest via
`PsrAdapter::serverRequestFromGlobals`, threads through
`Pipeline` (PSR-15-native since the framework's middleware contract
flipped to PSR-15), and emits via `PsrAdapter::emit`. The pair
isolates the per-request cost of going PSR-7-native end-to-end —
see `bench/ab/experiments/2026-05-01-psr7-end-to-end.md`.

Every app exposes the **same three routes** (four cases, since POST
exercises both the success and 422 paths):

| Route                          | Behaviour                             |
|---|---|
| `GET /hello`                   | 200 `{"hello":"world"}`              |
| `GET /products/{id:int}`       | 200 `{"id":<int>, "name":"Product <id>"}` |
| `POST /products` (valid body)  | 201 `{"id":1,"name":..,"price":..}`  |
| `POST /products` (invalid)     | 422 `application/problem+json` listing field errors |

Validation rules are identical across apps: `name` required + 1–100
chars, `price` required + ≥ 0. Slim, Symfony, and raw hand-roll the
checks; Rxn drives them off `#[Required]` / `#[Length]` / `#[Min]`
attributes via `Binder::bind()`.

## Run it

```bash
php bench/compare/run.php                                   # all four, defaults
php bench/compare/run.php --frameworks=rxn,raw              # subset
php bench/compare/run.php --duration=10 --concurrency=50    # heavier sweep
php bench/compare/run.php --skip-install                    # already installed?
```

The first run for `slim` / `symfony` triggers `composer install --no-dev`
inside their app directories. Subsequent runs reuse the same vendor
tree. `rxn` and `raw` need no install — they share the project-root
vendor or none at all.

Defaults: concurrency=20, duration=5s per route, warmup=1s. Output
goes to stderr (per-route progress) and stdout (final markdown
table). A timestamped copy lands in `bench/compare/results/`.

## What the numbers actually mean

`php -S` is a **development** server. The numbers it produces under
this harness are useful for **comparing frameworks on the same rig**;
they are not directly comparable to published numbers from
`wrk + nginx + PHP-FPM` setups.

What the harness *does* control for:
- Identical PHP version / opcache state across runs.
- Identical route table, identical validation rules.
- Same load generator, same warmup discipline.
- 4 worker processes per `php -S` (`PHP_CLI_SERVER_WORKERS=4`) so
  concurrency is real, not serialized.
- Server stdout/stderr go to a tempfile, not a pipe. Pipe-buffer
  blocking under load is the kind of artifact that silently caps
  these benchmarks at ~150 rps. Don't use a pipe.

What it doesn't:
- No HTTP/2, no TLS, no realistic upstream latency.
- The load generator runs in the same process the user reads
  output from. Above ~50 concurrency you start measuring its
  curl_multi overhead instead of the server's.
- Single-machine, loopback only — no network jitter modelled.
- No body-size sweep, no streaming, no `Accept-Encoding: gzip`.

## Reference numbers (this dev workstation)

PHP 8.4.19, OPcache on, no JIT, `php -S` with 4 workers,
concurrency=10, duration=2s. Results vary across runs by ±5%; rerun
on your own hardware before quoting them.

```
Framework  GET /hello   GET /products/{id}   POST /products (valid)   POST /products (422)
rxn          9,800        10,000               10,700                   10,400
raw          8,400         8,500                8,400                    8,200
symfony      5,100         5,150                5,000                    5,170
slim         4,650         4,750                4,680                    4,670
```

Why Rxn edges out raw: the raw app pays a `parse_url` + ordered
`if`/`preg_match` chain on every request; Rxn's `Router::match`
walks a precompiled regex table on a path that's already gone
through opcache. The differences shrink as concurrency rises and
the load generator starts to be the bottleneck.

## See also

- [`flexibility.md`](flexibility.md) — feature matrix (typed
  routes, DTO binding, Problem Details, OpenAPI, in-process test
  client, ORM included, …) per framework.
- [`docs/benchmarks.md`](../../docs/benchmarks.md) — Rxn's own
  microbenchmarks for the building blocks (router, container,
  validator, query builder, ActiveRecord hydration, PSR-7
  hydration). Cleaner numbers than this harness, but only Rxn.
