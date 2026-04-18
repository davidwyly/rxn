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
router.match.static                 2,012,215            497
router.match.single_param           1,893,140            528
router.match.multi_param            1,314,308            761
router.match.miss                   1,841,164            543
pipeline.3layer                     1,576,172            634
validator.check.clean                 330,545          3,025
container.get.depth_3                 383,927          2,605
psr7.from_globals                      83,033         12,043
```

## What's covered

| Case | Measures |
|---|---|
| `router.match.static` | Matching a registered static path |
| `router.match.single_param` | Single `{placeholder}` capture |
| `router.match.multi_param` | Two placeholders in the same path |
| `router.match.miss` | Walking the full route table before returning null |
| `pipeline.3layer` | Rxn-typed pipeline with three no-op middlewares |
| `validator.check.clean` | `Validator::check` against a 4-field rule set |
| `container.get.depth_3` | Autowiring `A → B → C` from a fresh container |
| `psr7.from_globals` | `PsrAdapter::serverRequestFromGlobals()` full-stack |

## Keeping the numbers honest

- Never include cold-start costs; `bin/bench` warms up before timing.
- Never inline a workload that gets optimised away — each closure
  captures state so the result is observable.
- Don't publish single-run numbers; variance across runs for the
  same case can be ±5%. Report ranges if you're publishing.
- If you change a building block's hot path, run `bin/bench <case>`
  before and after and put both in your commit message.
