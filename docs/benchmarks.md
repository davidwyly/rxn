# Benchmarks

Microbenchmarks for the framework's building blocks. Run with:

```
php bin/bench              # all cases
php bin/bench router       # filter by substring match on case name
```

Each case runs for ~250ms after a 50ms warmup on a single process.
This is **not** a full-stack HTTP benchmark — for end-to-end
throughput see `bench/compare/` (cross-framework HTTP under
`php -S` per-request worker mode).

## Reference numbers

These are the numbers produced on the development workstation
(PHP 8.4.19, OPcache on, no JIT, `bin/bench` in a tight loop) under
typical mixed-load conditions. On a quiet box every line scales up
by roughly 1.5×–2× — psr7.from_globals has been seen north of 200k
on an unloaded run. Your mileage will vary; rerun `bin/bench` on
your own hardware for apples-to-apples comparisons, and prefer
`bench/ab.php` for the side-by-side numbers that are robust to
host-level load.

```
case                                  ops/sec          ns/op
------------------------------ -------------- --------------
router.match.static                 1,870,833            535
router.match.single_param           1,185,300            844
router.match.multi_param              982,663          1,018
router.match.miss                   1,579,376            633
router.match.many.first_verb_hit    1,938,300            516
router.match.many.last_verb_hit     1,911,132            523
router.match.many.miss              2,459,092            407
pipeline.3layer                       979,976          1,020
validator.check.clean                 255,262          3,918
validator.check.compiled              626,204          1,597
binder.bind.runtime                   141,955          7,044
binder.bind.compiled                  908,008          1,101
container.get.depth_3                 496,028          2,016
builder.select.compound                51,563         19,394
builder.select.subquery                78,436         12,749
builder.insert.multirow               401,013          2,494
builder.update.simple                 277,508          3,603
builder.delete.simple                 373,151          2,680
active_record.hydrate_100             111,380          8,978
psr7.from_globals                     126,050          7,933
```

## What's covered

| Case | Measures |
|---|---|
| `router.match.static` | Matching a registered static path |
| `router.match.single_param` | Single `{placeholder}` capture |
| `router.match.multi_param` | Two placeholders in the same path |
| `router.match.miss` | Walking the full route table before returning null |
| `router.match.many.first_verb_hit` | 20-route table, hit on the first registered route |
| `router.match.many.last_verb_hit` | 20-route table, hit on the last verb's last entry |
| `router.match.many.miss` | 20-route table, full miss (verb-bucketing exposure case) |
| `pipeline.3layer` | Rxn-typed pipeline with three no-op middlewares |
| `validator.check.clean` | `Validator::check` against a 4-field rule set (runtime path) |
| `validator.check.compiled` | Same payload, eval-compiled closure from `Validator::compile($rules)` — schema-compiled rule set, ~2.4× faster than the runtime path |
| `binder.bind.runtime` | `Binder::bind` hydrating a 5-property DTO with mixed validation attributes |
| `binder.bind.compiled` | Same payload, eval-compiled closure from `Binder::compileFor($class)` — schema-compiled DTO hydration, ~6.4× faster than the runtime path |
| `container.get.depth_3` | Autowiring `A → B → C` from a fresh container |
| `builder.select.compound` | `Query` with SELECT + LEFT JOIN + multi-WHERE + GROUP BY + ORDER BY + LIMIT, materialised via `toSql()` |
| `builder.select.subquery` | SELECT with a `selectSubquery(...)` correlated subquery |
| `builder.insert.multirow` | `Insert` with three rows |
| `builder.update.simple` | `Update` with SET + WHERE |
| `builder.delete.simple` | `Delete` with WHERE |
| `active_record.hydrate_100` | `ActiveRecord::hydrate` on 100 rows |
| `psr7.from_globals` | `PsrAdapter::serverRequestFromGlobals()` full-stack |

## A/B'ing a candidate optimisation

When you change a hot path, prove the change moves the needle
before shipping it. `bench/ab.php` materialises two git refs into
their own worktrees, runs `bin/bench --json` against each N
times, and reports per-case median + range + verdict (win /
regression / noise / uncertain). See
[`bench/ab/README.md`](../bench/ab/README.md) for the full
workflow. Past experiments — including negative results — live
under [`bench/ab/experiments/`](../bench/ab/experiments/).

## Keeping the numbers honest

- Never include cold-start costs; `bin/bench` warms up before timing.
- Never inline a workload that gets optimised away — each closure
  captures state so the result is observable.
- Don't publish single-run numbers; variance across runs for the
  same case can be ±5%. Report ranges if you're publishing.
- If you change a building block's hot path, run `bin/bench <case>`
  before and after and put both in your commit message.
