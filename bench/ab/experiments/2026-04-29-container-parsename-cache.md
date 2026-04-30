# Container parseClassName cache

**Date:** 2026-04-29
**Decision:** **Merged** into `claude/code-review-pDtRd` as
`b4f556a perf(Container): cache parseClassName results`. Stacks
on the earlier reflection / plan caches for a cumulative ~66%
improvement on `container.get.depth_3` over the original baseline.

## Hypothesis

`parseClassName` is a pure function (`'\\' . ltrim($input, '\\')`)
called multiple times per `get()`:

- Once at the entry of `get()` to normalise the requested name.
- Once per recursive autowire descent (the type from
  `ReflectionNamedType::getName()` is a constructor parameter
  type that arrives without a leading backslash).
- Once in `addInstance()` after construction.

For `container.get.depth_3` (a 3-level chain), that's 6+ calls to
`parseClassName` per top-level `get()`, each doing `ltrim` +
string concatenation. Memoise by input.

## Change

```php
private static array $parsedNameCache = [];

private function parseClassName($class_name)
{
    return self::$parsedNameCache[$class_name]
        ??= "\\" . ltrim($class_name, '\\');
}
```

No eviction; the cache is bounded by the same class-graph size
the existing reflection caches already accept.

## Result

```
A = claude/code-review-pDtRd (562425d585c8)
B = bench/ab-container-parsename-cache (2c18f2202038)
runs = 5

| case                    | A median ops/s | B median ops/s |    Δ %  | A range            | B range            | verdict |
|-------------------------|---------------:|---------------:|--------:|--------------------|--------------------|---------|
| container.get.depth_3   |        470,164 |        536,334 | +14.1%  | 467,346..482,126   | 519,222..548,988   | win     |
```

A.max = 482,126 < B.min = 519,222. Every B run beat every A run.

## Cumulative wins on `container.get.depth_3`

| layer added | median ops/s | Δ from previous | Δ from baseline |
|---|---:|---:|---:|
| baseline (no caches) | ~323,000 | — | — |
| + ReflectionClass cache (`17b15a9`) | ~369,000 | +14.4% | +14.4% |
| + constructor-plan cache (`76b7b51`) | ~415,000 | +12.6% | +28.5% |
| + parseClassName cache (`b4f556a`) | ~536,000 | +14.1% | **+65.9%** |

The container's autowire path is now ~1.66× the original
throughput at depth 3. The composition is satisfying: each layer
removes a different per-call cost (reflection objects → parameter
introspection → name normalisation), and each contributes ~12–14%
on top of the previous one.

## Test impact

253 tests / 572 assertions, all green. `ContainerTest` exercises
multiple bind / get sequences against the same class names — the
cache is exactly what's expected to be invariant across those.

## Caveats

- The `is_string($target) && class_exists($target)` branch in
  `get()` calls `class_exists` on the raw target string, not the
  cached version. That's intentional: the binding stores
  whatever the caller passed, and `class_exists` itself short-
  circuits for known classes via the autoloader's cache.
- One remaining `ltrim($class_name, '\\')` call inside
  `get()` (the Container::class identity check) doesn't go
  through the cache. Single comparison; not worth caching.
