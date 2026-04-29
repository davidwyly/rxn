# Container::get() hot-path trim — three small wins

**Date:** 2026-04-29
**Decision:** **Merged** as
`935a072 perf(Container): trim get() hot path`. Stacking with the
prior reflection / plan / parseName caches; this round is about
shaving the orchestration cost that remains *after* the caches hit.

## Hypothesis

After the three earlier container experiments (reflection cache,
constructor-plan cache, parseClassName cache), `Container::get()`
spends most of its time on cache *hits* — the orchestration code
around the cache lookups now dominates. Three small frictions in
that orchestration:

1. **Self-lookup ltrim allocations.** `get()` checks
   `if (ltrim($class_name, '\\') === ltrim(Container::class, '\\'))`
   on every call to short-circuit `$container->get(Container::class)`.
   Two `ltrim()` allocations per dispatch, even though
   `$class_name` was just normalised.
2. **`addInstance()` re-parses an already-normalised name.** After
   `generateInstance()` returns, `get()` calls `addInstance()`,
   which calls `parseClassName()` again. The lookup hits the
   parsed-name cache — fast but not free.
3. **Recursive `get()` for autowired deps re-normalises the FQCN.**
   Plan directives store the type name as `$type->getName()`,
   which `ReflectionNamedType::getName()` returns *without* a
   leading backslash. The recursive `get($directive[1])` then
   pays for the parseClassName cache lookup. Pre-normalising the
   plan target at compile time avoids the lookup.

## Change

**(1) Replace ltrim self-check with a precomputed const compare:**

```php
private const SELF_KEY = '\\Rxn\\Framework\\Container';

if ($class_name === self::SELF_KEY) {
    return $this;
}
```

`$class_name` is already in `\Foo\Bar` form post-`parseClassName()`,
so a string compare is exact.

**(2) Inline the post-generate write in `get()`:**

```php
// was: $this->addInstance($class_name, $instance);
$this->instances[$class_name] = $instance;
```

`$class_name` is already normalised; the second `parseClassName()`
in `addInstance()` is redundant on this branch. (`addInstance()` is
still public and still normalises for external callers.)

**(3) Pre-normalise autowire targets in `constructorPlanFor()`:**

```php
$name = $type->getName();
$normalised = self::$parsedNameCache[$name]
    ??= '\\' . ltrim($name, '\\');
$plan[] = ['autowire', $normalised];
```

Once the plan is cached, every recursive `get()` lands on a
parsed-name cache hit — same string identity, no work.

Branch: `bench/ab-container-hotpath-trim`, commit `622c6bb`.

## Result

```
A = claude/code-review-pDtRd (a1b623d325b8)
B = bench/ab-container-hotpath-trim (622c6bba7b0e)
runs = 5

| case                    | A median ops/s | B median ops/s |   Δ %  | A range               | B range               | verdict |
|-------------------------|---------------:|---------------:|-------:|-----------------------|-----------------------|---------|
| container.get.depth_3   |        498,868 |        603,325 | +20.9% | 475,710..522,744      | 579,504..614,519      | win     |
```

A.max = 522,744 < B.min = 579,504. Clean win.

## Why ~21% from such small changes

Three pieces compound on a depth-3 chain:

- The self-lookup ltrim pair (~50ns × 2) fires on **every**
  recursive `get()`. Depth 3 = 3 × 100ns = ~300ns saved.
- The `addInstance()` skip removes a method dispatch + a
  cache lookup ~× 3 = ~200ns saved.
- The pre-normalised autowire target removes a cache lookup
  per recursive descent (depth 3 has 2 descents) = ~100ns saved.

That's ~600ns out of a ~2.0µs baseline ≈ 30% in theory. The
realised 21% is consistent with that bound minus whatever the
JIT can already fold; further savings would require touching the
`is_subclass_of` / `class_exists` / instance cache lookups, which
are PHP internals.

## Test impact

`ContainerTest`: 7 tests / 8 assertions, all green. Full suite:
253 / 573, all green. Bound interfaces, factory closures,
circular-dependency detection, and parameter overrides all still
exercise the rewritten code paths.

## Cumulative container scoreboard

Stacking on the three earlier wins:

```
baseline (pre-experiments):   ~323,000 ops/s
+ reflection cache:           ~411,000  (+27%)
+ constructor-plan cache:     ~497,000  (+21%)
+ parseClassName cache:       ~535,000  (+8%)
+ hot-path trim (this):       ~603,000  (+13%)
total:                        +87% vs baseline
```

The container is now operating close to the bytecode floor of
"build N objects with N reflection-driven argument lists." The
remaining ~1.65µs is dominated by `newInstanceArgs()` and the
unavoidable hash-table writes for the resolving / instances
arrays.

## Notes

- `addInstance()` stays as the public API for external callers
  (e.g. test harnesses that prebuild instances). Only `get()`'s
  internal fast path skips it.
- The `SELF_KEY` const hardcodes the container's FQCN. If the
  class is ever moved, this constant has to move with it. An
  E_ALL-clean `'\\' . self::class` initialiser isn't allowed in a
  const expression, so the literal is the simplest fix.
- The autowire-name pre-normalisation also makes the plan slightly
  larger for cold builds (one `??=` write per entry), but those
  are amortised across every call after the first.
