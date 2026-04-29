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
router.match.static                 1,634,774            612
router.match.single_param           1,285,604            778
router.match.multi_param            1,112,624            899
router.match.miss                   1,713,684            584
router.match.many.first_verb_hit    1,835,664            545
router.match.many.last_verb_hit     1,209,968            826
router.match.many.miss              1,543,544            648
pipeline.3layer                     1,406,472            711
validator.check.clean                 292,871          3,414
container.get.depth_3                 415,262          2,408
builder.select.compound                67,744         14,762
builder.select.subquery                96,286         10,386
builder.insert.multirow               527,794          1,895
builder.update.simple                 355,119          2,816
builder.delete.simple                 492,276          2,031
active_record.hydrate_100              86,967         11,499
psr7.from_globals                      73,145         13,671
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
