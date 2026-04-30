# Container constructor-plan cache

**Date:** 2026-04-29
**Decision:** **Merged** into `claude/code-review-pDtRd` as
`8667f8e perf(Container): pre-compute constructor plan per class`.

## Hypothesis

The previous reflection-class cache (`17b15a9`) only memoised
`new \ReflectionClass($class_name)`. `generateInstance` still
called `getConstructor()` and `getParameters()` per `get()`, then
called `getType()`, `isBuiltin()`, `isDefaultValueAvailable()`,
`getDefaultValue()`, `allowsNull()`, and `getName()` for every
parameter inside the per-call foreach. All of that is
class-invariant — we should be able to compile a per-class
"build recipe" once and then never touch a `ReflectionParameter`
on the hot path again.

## Change

Added a third static cache, `$constructorPlanCache`, populated by
`constructorPlanFor()`. Each cache entry is a list of resolution
directives — one per constructor parameter slot:

```
['autowire', $class]    → call $this->get($class)
['default',  $value]    → use the saved default literal
['null']                → pass null
['fail',     $param]    → throw with the recorded param name
```

`generateInstance` now does nothing more than walk the plan,
switch on the directive tag, and assemble the args array. No
`ReflectionParameter` accessors fire on the hot path after the
first lookup.

Construction-failure semantics are preserved: the `fail`
directive only throws when `array_key_exists($key,
$passed_parameters)` returned false, matching the original lazy
throw point.

## Result

```
A = claude/code-review-pDtRd (3eafce30c04c)  ← reflection-cache landed
B = bench/ab-container-constructor-plan (a6b01595237b)
runs = 5

| case                    | A median ops/s | B median ops/s |    Δ %  | A range            | B range            | verdict |
|-------------------------|---------------:|---------------:|--------:|--------------------|--------------------|---------|
| container.get.depth_3   |        368,755 |        415,375 | +12.6%  | 362,328..377,542   | 411,358..421,766   | win     |
```

A.max = 377,542 < B.min = 411,358 — every B run beat every A run.

Stacked on top of the reflection cache (which itself was +14.4%
over the no-cache baseline of ~323K ops/s), the cumulative
improvement on `container.get.depth_3` is now **+28%** vs. the
original code:

```
original (no caches):                ~323,000 ops/s
+ ReflectionClass cache:             ~369,000 ops/s   (+14.4%)
+ constructor-plan cache:            ~415,000 ops/s   (+12.6% over previous)
                                     ──────────────
                                     +28.5% cumulative
```

## Test impact

253 tests / 572 assertions, all green on the merged branch.
Existing `ContainerTest` coverage (autowire, circular dependency
detection, scalar default + nullable + fallthrough) exercises
every directive type the new plan records.

## Caveats / next steps

- The plan stores `getDefaultValue()` results directly. PHP
  constructor defaults are compile-time constants (literals,
  enum cases, or `new ClassName()` since PHP 8.1) — sharing them
  is safe, but if a future binding factory wanted a per-call
  default expression, this cache would foil it. Not a current
  concern; flag if it becomes one.
- Static caches are process-lifetime. Under PHP-FPM that's one
  request. Under PHP-PM / Swoole / RoadRunner it's "until the
  worker recycles". No eviction; bounded by the class graph size,
  same as autoloading already accepts.
