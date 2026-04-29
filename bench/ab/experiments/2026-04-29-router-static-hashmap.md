# Router static-path hashmap dispatch

**Date:** 2026-04-29
**Decision:** **Merged** as
`ab83b60 perf(Router): O(1) hashmap dispatch for static routes`.
The biggest single change on the router this branch has shipped —
wins on all seven router cases including 2× speedups on the
"many" cases.

## Hypothesis

`Router::match()` was doing a verb-bucket linear walk: pull every
route registered for the verb, run `preg_match` against each until
one matched. That's O(n) regex evaluations even when the path is
literally `/products` and a registered exact-match route exists.

FastRoute, Symfony's CompiledUrlMatcher, and Laravel all skip the
regex on static routes by maintaining a separate
`(method, path) → route` hashmap. PHP array hashes are O(1) lookup
on string keys, so the whole match collapses to one
`isset($map[$method][$path])` + a return.

## Change

Two parts.

**At registration:** routes whose compiled `paramNames` is empty
go into `$staticRoutes[$method][$normalizedPath] = $route` instead
of `$routesByMethod[$method][]`. Placeholder routes still go into
the bucket as before. First-registered wins on duplicates.

**At match time:** check `$staticRoutes[$method][$path]` first; on
hit, return immediately with `params=[]`. On miss, fall through to
the existing verb-bucket regex walk for placeholder routes.

**Registration-order semantics:** The pre-existing test
`testFirstRegisteredRouteWins` registers `/products/{id}` then
`/products/new` and expects the placeholder to win when matching
`/products/new`. To preserve that, when registering a static
route we run the existing placeholder regexes for the same method
against the static's path; if any match, the static is *shadowed*
and stays out of the hashmap (it still lives in `$this->routes`,
which the bucket walks over). The shadowing check is a one-time
cost paid at `add()`; it never affects `match()`.

```php
private array $staticRoutes = [];

public function add(...) {
    ...
    $isStatic = $params === [];
    foreach ($normalized as $m) {
        if ($isStatic) {
            if ($this->isShadowedByPlaceholder($m, $staticPath)) continue;
            if (!isset($this->staticRoutes[$m][$staticPath])) {
                $this->staticRoutes[$m][$staticPath] = $route;
            }
        } else {
            $this->routesByMethod[$m][] = $route;
        }
    }
}

public function match(string $method, string $path): ?array {
    ...
    $hit = $this->staticRoutes[$method][$path] ?? null;
    if ($hit !== null) return [/* handler/pattern/name/middlewares, params=[] */];
    // ... existing bucket walk for placeholder routes
}
```

Branch: `bench/ab-router-static-hashmap`, commit `f86d582`.

## Result

```
A = claude/code-review-pDtRd (21ac902f8c52)
B = bench/ab-router-static-hashmap (f86d5824d837)
runs = 5

| case                              | A median ops/s | B median ops/s |   Δ %   | A range                | B range                | verdict |
|-----------------------------------|---------------:|---------------:|--------:|------------------------|------------------------|---------|
| router.match.static               |      1,858,493 |      2,784,369 |  +49.8% | 1,829,357..1,872,481   | 2,727,726..2,870,792   | win     |
| router.match.single_param         |      1,385,884 |      1,665,828 |  +20.2% | 1,339,008..1,452,028   | 1,601,008..1,700,096   | win     |
| router.match.multi_param          |      1,244,392 |      1,413,760 |  +13.6% | 1,217,648..1,287,672   | 1,395,256..1,443,628   | win     |
| router.match.miss                 |      1,959,264 |      2,349,708 |  +19.9% | 1,826,320..1,995,984   | 2,274,576..2,358,008   | win     |
| router.match.many.first_verb_hit  |      2,100,596 |      2,708,212 |  +28.9% | 2,073,600..2,133,596   | 2,631,988..2,788,380   | win     |
| router.match.many.last_verb_hit   |      1,362,160 |      2,686,596 |  +97.2% | 1,345,512..1,403,144   | 2,669,912..2,726,456   | win     |
| router.match.many.miss            |      1,767,964 |      3,477,564 |  +96.7% | 1,692,860..1,795,516   | 3,393,844..3,573,872   | win     |
```

All seven cases moved with non-overlapping ranges.

## Why every case improved (not just static)

The hashmap doesn't just speed up the hits it serves; it also
trims the placeholder bucket, since static routes no longer live
there.

- `router.match.static`: was 5-route bucket walk that hit on entry
  3. Now a single hashmap lookup. ~1.5× speedup.
- `router.match.single_param` / `multi_param`: bucket used to
  carry 5 routes (3 static + 2 placeholder). Now it's just the 2
  placeholder routes, so the placeholder regex walk has half the
  entries to skip past on the way to the matching one. ~1.2×.
- `router.match.miss`: bucket used to walk all 5; now walks 2.
- `router.match.many.first_verb_hit`: bucket of 5 → hashmap O(1).
  ~1.3×.
- `router.match.many.last_verb_hit`: bucket of 5 with the hit at
  the end → hashmap O(1). ~2×.
- `router.match.many.miss`: GET bucket of 5 walks → empty bucket,
  hashmap miss returns null. ~2×.

## Test impact

Full suite: 253 tests / 573 assertions, all green. Notably
`testFirstRegisteredRouteWins` still passes — the shadow-detection
keeps the placeholder-then-static ordering case correct.

`hasMethodMismatch()` still walks `$this->routes` (the master
list), so 405 detection is unchanged.

## Where this trick is from

This is the FastRoute "static-paths hashmap" optimization,
borrowed by Slim, Mezzio, and (under different machinery)
Symfony's CompiledUrlMatcher. The novelty here is the
shadow-detection step at registration, which preserves Rxn's
linear-scan registration-order semantics without sacrificing the
fast path for the common case.

## Notes

- Memory cost: one extra associative-array entry per static route.
  For a 100-route table with 80 static, that's 80 array entries —
  cheap.
- `add()` for a static route now pays one `preg_match` per
  previously-registered placeholder in the same verb bucket. For
  apps with thousands of routes this could be measurable at boot,
  but registration is one-shot at app startup so it's amortised.
- The bench's many-case table happens to be 100% static, which
  flatters the hashmap. Real apps with mixed static + placeholder
  tables (the typical CRUD shape) will see most of the benefit on
  the static portion and the bucket-trimming benefit on
  placeholder paths.
