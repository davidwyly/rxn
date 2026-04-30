# Router::match verb bucketing

**Date:** 2026-04-29
**Decision:** **Merged** into `claude/code-review-pDtRd` as
`8667f8e perf(Router): bucket routes by HTTP method for O(verb-slice) match`.
The biggest single A/B win measured to date.

## Hypothesis

`Router::match` walks every registered route on a miss and on
most hits, even though most routes don't accept the request's
verb. With more than a handful of routes — a real-app route table
— paying for every verb on every match is wasted work. Bucketing
by verb at registration should make match cost
**O(verb-slice)** instead of **O(total)**.

The existing 5-route bench couldn't expose this: every probe was
GET against a table with 4 GETs and 1 POST. Prep commit `cc20fa0`
added a 20-route table (5 per verb across GET / POST / PUT /
DELETE) and three new cases:

- `router.match.many.first_verb_hit`  — best case, hit the head
- `router.match.many.last_verb_hit`   — last verb, last bucket entry
- `router.match.many.miss`            — walks the whole table linearly

## Change

```php
/** @var array<string, Route[]> */
private array $routesByMethod = [];

public function add(...): Route {
    ...
    $route = new Route(...);
    $this->routes[] = $route;
    foreach ($normalized as $m) {
        $this->routesByMethod[$m][] = $route;
    }
    return $route;
}

public function match(string $method, string $path): ?array {
    ...
    $bucket = $this->routesByMethod[$method] ?? [];
    foreach ($bucket as $route) {
        if (!preg_match($route->regex, $path, $matches)) continue;
        // No more in_array($method, $route->methods) check —
        // bucket membership already implies it.
        ...
    }
    return null;
}
```

Routes registered for multiple verbs (`add(['GET','HEAD'], ...)`,
or `any()` which expands to all 7 methods) appear in every
relevant bucket pointing at the same `Route` instance.
First-registered-wins ordering is preserved within each bucket.

`hasMethodMismatch()` and `indexNames()` still walk the linear
`$routes` list — they need cross-verb ordering, and 405 isn't
the hot path.

## Result

```
A = claude/code-review-pDtRd (cc20fa0dcd98)
B = bench/ab-router-verb-buckets (e79d639972d1)
runs = 5

| case                                | A median ops/s | B median ops/s |     Δ %  | A range                | B range                | verdict |
|-------------------------------------|---------------:|---------------:|---------:|------------------------|------------------------|---------|
| router.match.many.first_verb_hit    |      1,807,680 |      1,859,356 |   +2.9%  | 1,645,780..1,846,220   | 1,846,436..1,879,496   | noise   |
| router.match.many.last_verb_hit     |        547,012 |      1,213,160 | +121.8%  |   539,146..  551,732   | 1,155,724..1,224,252   | win     |
| router.match.many.miss              |        637,868 |      1,577,288 | +147.3%  |   633,437..  641,046   | 1,537,100..1,581,536   | win     |
| router.match.miss (5-route)         |      1,609,064 |      1,728,484 |   +7.4%  | 1,597,444..1,622,372   | 1,728,012..1,735,880   | win     |
| router.match.multi_param            |      1,096,096 |      1,107,992 |   +1.1%  | 1,065,216..1,100,900   | 1,059,180..1,123,260   | noise   |
| router.match.single_param           |      1,263,772 |      1,278,308 |   +1.2%  | 1,256,780..1,272,364   | 1,268,704..1,297,828   | noise   |
| router.match.static                 |      1,606,128 |      1,649,200 |   +2.7%  | 1,551,528..1,619,954   | 1,629,322..1,663,244   | noise   |
```

The two `many.*` "win" cases are **2.2× and 2.5× faster**
respectively. The `first_verb_hit` case is correctly identified
as noise — when the first verb's first registered route matches,
linear and bucketed scans do the same work.

The existing 5-route cases are mostly noise, which is the right
answer: with 4 GETs + 1 POST, walking the whole table on a GET
miss saves you exactly one route, which is below the variance
floor on every case except `match.miss` (which the harness flags
as a +7.4% win).

## Test impact

253 tests / 572 assertions, all green. `RouterTest` exercises
multi-method routes, `any()`, `add(['GET','HEAD'], ...)`, named
routes, groups + nested groups, typed constraints — all of which
intersect with the new bucket index. None broke.

## Why this won where the PSR factory cache didn't

Mechanism size. The factory cache (negative result on
`2026-04-29-psr-adapter-factory-cache.md`) saved one allocation
per request — nanoseconds. Verb bucketing avoids walking
**(total_routes − verb_slice_size)** routes on every match.
On a 20-route table accepting an HTTP method that maps to 5
routes, that's 15 fewer regex compiles per call. The win scales
with route-table size; a 100-route app should see it more
sharply still.

## Caveats / next steps

- The benefit grows with verb fan-out. For a single-verb app
  (e.g., a webhook receiver that only accepts POST) it shrinks
  to "one less in_array() per match" — still a small win.
- A possible follow-up: combine bucketed routes within a verb
  into a single alternation regex (`#^(/static$)|(/products/(\d+)$)|...#`).
  Single `preg_match` instead of N. Risky on first-registered-wins
  ordering when patterns overlap; would need careful capture-group
  bookkeeping. Not pursued yet.
