# Container reflection cache

**Date:** 2026-04-29
**Decision:** **Merged** into `claude/code-review-pDtRd` as
`17b15a9 perf(Container): cache ReflectionClass + isService lookups`.

## Hypothesis

`Container::get()` reaches `isService()` and `generateInstance()` on
every call, and each builds a fresh `\ReflectionClass($class_name)`.
Since the class graph is fixed for the process lifetime, those
reflection objects are pure waste on every call after the first.
Caching them by class name should shave measurable time off
`container.get.depth_3`.

## Change

```php
private static array $reflectionCache = [];
private static array $isServiceCache  = [];

private static function reflectionFor(string $class_name): \ReflectionClass
{
    return self::$reflectionCache[$class_name]
        ??= new \ReflectionClass($class_name);
}

private function isService($class_name)
{
    return self::$isServiceCache[$class_name]
        ??= self::reflectionFor($class_name)->isSubclassOf(Service::class);
}

private function generateInstance($class_name, array $passed_parameters)
{
    $reflection = self::reflectionFor($class_name);
    // ... rest unchanged
}
```

Both caches are static — they live for the lifetime of the PHP
process, which is one request under PHP-FPM and arbitrarily long
under PHP-PM / Swoole / RoadRunner. There's no eviction; the cache
is bounded by the number of classes the container ever sees,
which is the same bound autoloading already accepts.

## Result

```
A = claude/code-review-pDtRd (4e7ad24ccaff)
B = bench/ab-container-reflection-cache (799a91dcf358)
runs = 5

| case                    | A median ops/s | B median ops/s |    Δ %  | A range            | B range            | verdict |
|-------------------------|---------------:|---------------:|--------:|--------------------|--------------------|---------|
| container.get.depth_3   |        323,050 |        369,675 | +14.4%  | 318,073..323,677   | 358,169..383,904   | win     |
```

`A.max = 323,677 < B.min = 358,169` — every B run beat every A
run. The median improvement is +14.4%, well above the 5% noise
floor.

## Test impact

Existing `ContainerTest` (7 tests / 8 assertions) passes unchanged.
The full framework suite (253 tests / 572 assertions) is also green
on the merged branch.

## Notes / next steps

- The 14% number is for `container.get.depth_3`, which builds a
  fresh `Container` per iteration and resolves a 3-level chain.
  The win in real-app dispatch (where the container is reused
  across requests under FPM with opcache) should be at least this
  large per request.
- A natural follow-up is to cache the constructor `getParameters()`
  list in the same way; it's the next-biggest reflection cost in
  `generateInstance()`. Filed as a candidate, not run yet.
