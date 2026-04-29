# Benchmarks

Microbenchmarks for the framework's building blocks. Run with:

```
php bin/bench              # all cases
php bin/bench router       # filter by substring match on case name
```

Each case runs for ~250ms after a 50ms warmup on a single process.
This is **not** a full-stack HTTP benchmark — `App::run()` still has
open bugs that make end-to-end numbers meaningless until it's
rewritten.

## Reference numbers

These are the numbers produced on the development workstation
(PHP 8.4.19, OPcache on, no JIT, `bin/bench` in a tight loop). Your
mileage will vary; rerun `bin/bench` on your own hardware for
apples-to-apples comparisons.

```
case                                  ops/sec          ns/op
------------------------------ -------------- --------------
router.match.static                 1,868,756            535
router.match.single_param           1,461,160            684
router.match.multi_param            1,258,100            795
router.match.miss                   1,960,032            510
router.match.many.first_verb_hit    2,118,656            472
router.match.many.last_verb_hit     1,350,368            741
router.match.many.miss              1,740,140            575
pipeline.3layer                     1,607,092            622
validator.check.clean                 357,012          2,801
container.get.depth_3                 535,048          1,869
builder.select.compound                77,054         12,978
builder.select.subquery               106,430          9,396
builder.insert.multirow               590,960          1,692
builder.update.simple                 411,441          2,430
builder.delete.simple                 558,460          1,791
active_record.hydrate_100             181,330          5,515
psr7.from_globals                      81,513         12,268
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
| `validator.check.clean` | `Validator::check` against a 4-field rule set |
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
