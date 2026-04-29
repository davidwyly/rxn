# Router combined-alternation regex (mixed result)

**Date:** 2026-04-29
**Decision:** **Not merged.** Wins on miss / late-hit cases, but
regresses the common case (early-bucket hit) by ~23%. Real apps
overwhelmingly take the early-hit path; the regression dominates
the practical win on misses.

This is the most useful kind of negative result the harness has
produced so far — a change that *looks* obviously good (one
preg_match instead of N) and turns out to have a real common-case
cost. Documented here so the next maintainer doesn't re-run the
same idea without a different mechanism in mind.

## Hypothesis

After verb-bucketing (`8667f8e`), `Router::match` walks each
bucket linearly with one `preg_match` per route. PCRE's
alternation engine should be able to do the same work in a single
match using `(*MARK:rN)` sentinels to identify which alternative
won. Going from N preg_match calls to one should be a clear win,
especially on misses where the linear scanner walks every entry.

## Change

```php
private array $bucketCompiled = [];   // verb → ['regex' => str, 'marks' => [mark => ['route' => Route, 'firstGroup' => int]]]
private bool $bucketsDirty   = false; // re-compile lazily after add()

private function compileBuckets(): void {
    foreach ($this->routesByMethod as $verb => $routes) {
        $parts = [];
        $marks = [];
        $groupOffset = 1;
        foreach ($routes as $i => $route) {
            $body = self::stripDelimitersAndAnchors($route->regex);
            $mark = 'r' . $i;
            $parts[] = $body . '(*MARK:' . $mark . ')';
            $marks[$mark] = ['route' => $route, 'firstGroup' => $groupOffset];
            $groupOffset += count($route->paramNames);
        }
        $this->bucketCompiled[$verb] = [
            'regex' => '#^(?:' . implode('|', $parts) . ')$#',
            'marks' => $marks,
        ];
    }
    $this->bucketsDirty = false;
}
```

`match()` runs one `preg_match` against the bucket's combined
regex, reads `$matches['MARK']` to identify the route, and pulls
placeholders from the recorded `firstGroup` offset. Falls back to
a linear scan if MARK isn't surfaced (defensive — every PCRE2
build I tested supports it).

Branch: `bench/ab-router-combined-alternation`, commit `9f48a40`.

## Result

```
A = claude/code-review-pDtRd (562425d585c8) — verb buckets, linear scan
B = bench/ab-router-combined-alternation (9f48a40a4228) — combined alternation
runs = 5

| case                                | A median ops/s | B median ops/s |     Δ %  | A range                | B range                | verdict     |
|-------------------------------------|---------------:|---------------:|---------:|------------------------|------------------------|-------------|
| router.match.many.first_verb_hit    |      2,084,296 |      1,606,160 |  -22.9%  | 1,993,716..2,115,436   | 1,572,076..1,622,816   | regression  |
| router.match.many.last_verb_hit     |      1,374,796 |      1,566,140 |  +13.9%  | 1,333,340..1,390,592   | 1,543,896..1,610,244   | win         |
| router.match.many.miss              |      1,765,772 |      2,992,020 |  +69.4%  | 1,680,144..1,797,096   | 2,954,148..3,044,984   | win         |
| router.match.miss (5-route)         |      1,901,824 |      2,935,200 |  +54.3%  | 1,854,625..1,982,604   | 2,754,004..2,975,708   | win         |
| router.match.multi_param            |      1,248,844 |      1,310,232 |   +4.9%  | 1,142,976..1,257,044   | 1,246,972..1,330,660   | noise       |
| router.match.single_param           |      1,458,816 |      1,407,640 |   -3.5%  | 1,391,012..1,491,888   | 1,339,340..1,432,640   | noise       |
| router.match.static                 |      1,796,037 |      1,628,106 |   -9.4%  | 1,610,706..1,836,360   | 1,563,418..1,637,400   | uncertain   |
```

## Why the alternation hurts the early-hit case

PCRE compiles an alternation with branch-bookkeeping overhead
that a tight `^/products$` doesn't have. When the linear scanner
hits the very first registered route, it pays for one anchored
preg_match against a short pattern — the cheapest possible path.
The combined alternation pays for the same preg_match against a
much longer pattern with internal `|` boundaries, MARK setup, and
the engine's "did we land in branch N" tracking even when the
first alternative wins.

That overhead is fixed per call. On a miss it's amortised against
walking every other route (huge win). On a first-bucket hit it
*is* the cost (clear regression).

## What an actual real-app distribution looks like

A reasonable model for a healthy JSON API:
- ~95% of requests hit a registered route (front-end speaks the
  contract).
- Of the hits, route-table position skews early — common APIs
  put `/health`, `/me`, `/products` near the top of the table.
- ~5% of requests are misses (probes, scanners, occasional bugs).

Under that mix:
- early-hit regression of -23% applies to ~70% of requests
- late-hit win of +14% applies to ~25% of requests
- miss win of +69% applies to ~5% of requests
- net: roughly **−10% throughput** in the steady state.

## Decision

Not merged. The mechanism is correct (single regex < N regex on
misses), but the constant overhead of PCRE alternation outweighs
the savings on the common case. The branch
`bench/ab-router-combined-alternation` is preserved for the
historical record.

## Possible re-investigations (not pursued)

- **Trie-based prefix matching** before falling into a regex.
  Most real route tables share long static prefixes (`/api/v1/...`).
  A trie could narrow the candidate set to one or two routes
  before paying any regex cost. Bigger lift to implement; would
  need correctness tests for placeholder + static segments at the
  same depth.
- **Hybrid: alternation only when the bucket exceeds a threshold.**
  e.g. linear scan up to 8 routes per verb, alternation beyond.
  Looks like the right answer in principle but adds branchy code
  to the hot path. Worth a separate experiment if a real Rxn app
  reports >20 routes per verb in production.
- **Precompiled `T_PCRE_INTERNAL` study.** The PCRE JIT
  (`pcre.jit=1`) might change the calculus — JITed alternation
  could be cheaper than this baseline assumes. Would need a
  separate A/B with `pcre.jit` toggled.
