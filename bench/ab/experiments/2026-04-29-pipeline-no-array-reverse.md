# Pipeline: walk middlewares backward instead of array_reverse

**Date:** 2026-04-29
**Decision:** **Not merged** (noise).

## Hypothesis

`Pipeline::handle()` does

```php
foreach (array_reverse($this->middlewares) as $middleware) {
    $next = static function (Request $req) use ($middleware, $next): Response {
        return $middleware->handle($req, $next);
    };
}
```

per call. The `array_reverse` allocates a fresh reversed array every
time. Walking the original array backward by index would skip that
allocation:

```php
for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
    $middleware = $this->middlewares[$i];
    ...
}
```

Expected to shave a small amount per call.

## Result

```
A = claude/code-review-pDtRd (21ac902f8c52)
B = bench/ab-pipeline-no-array-reverse (25bfb845eaca)
runs = 5

| case               | A median ops/s | B median ops/s |   Δ %  | A range                 | B range                 | verdict |
|--------------------|---------------:|---------------:|-------:|-------------------------|-------------------------|---------|
| pipeline.3layer    |      1,494,713 |      1,498,854 |  +0.3% | 1,464,323..1,515,954    | 1,479,863..1,532,910    | noise   |
```

Ranges fully overlap. Δ is below the per-case run-to-run variance.

## Why

`array_reverse` on a 3-element packed array is sub-50ns — well under
1% of the ~620ns per-call cost. The closure allocations and dispatches
dominate; nothing else is going to move the needle on this case
without removing or hoisting the closures themselves, which is a
larger architectural change (and breaks correctness for re-entrant
or multi-call middlewares).

## Test impact

`PipelineTest`: 9 tests / 11 assertions, all green. No semantic change
— same iteration order, same chain shape.

## Notes

- Branch kept at `bench/ab-pipeline-no-array-reverse` for the record.
- A bigger pipeline win would require either pre-compiling the chain
  once and re-using it across handle() calls (only safe when the
  terminal callable is also stable, which is rarely the case in
  production routing) or moving to an index-based dispatcher that
  trades N closures for one — but the dispatcher variant ends up
  with N+1 closure allocations per call, which is worse, not better.
