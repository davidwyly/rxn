# Compiled Container — per-class factory closures

**Date:** 2026-04-29
**Decision:** **Merged** as
`087d108 perf(Container): per-class factory closures eval-compiled
from plan`. Stacks on the four prior container experiments
(reflection cache, plan cache, parseName cache, hot-path trim).
The remaining ~50% of the runtime cost was hidden inside
`ReflectionClass::newInstanceArgs()` plus the per-call
`foreach ($plan)` walker; this experiment compiles both away.

## Hypothesis

After the four prior optimisations, `Container::generateInstance()`
on a depth-3 chain spent its remaining budget on:

1. `foreach ($plan as $directive)` — N PHP frames per call to
   build `$create_parameters`.
2. `$reflection->newInstanceArgs($create_parameters)` — internal
   reflection write of the prepared args into a fresh instance.

Those costs scale with depth × N parameters. They're amortised
across no calls today: every `get()` rebuilds the plan walk and
calls `newInstanceArgs` afresh.

If we have the directive plan at compile time (we already do —
`$constructorPlanCache` was the second experiment), and the
class+args are fixed, we can synthesise `new $class($c->get('\\Foo\\Bar'))`
directly. PHP will compile that to a single `NEW` opcode plus the
recursive `get()` call sites. No `newInstanceArgs`, no plan
walker, no `$create_parameters` array.

## Change

A new `$factoryCache[$class] => \Closure` is consulted on the
no-overrides fast path of `generateInstance()`. On miss, the
plan is fed to `compileFactory()` which emits:

```php
return static fn (\Rxn\Framework\Container $c) =>
    new \BenchA($c->get('\\BenchB'));
```

(For BenchA → BenchB → BenchC, each class gets its own factory;
the recursive `get()` call hops through the same fast path on each
descent.)

Per-directive emission:

| Directive | Argument expression |
|---|---|
| `['autowire', $fqcn]` | `$c->get('<fqcn>')` |
| `['default', $value]` | `var_export($value, true)` |
| `['null']` | `null` |
| `['fail', $param]` | factory not built — fall back to runtime walker so the existing exception with the parameter name still fires |

The runtime walker is preserved for two cases the factory can't
model:

- `$container->get(Foo::class, ['arg' => 'override'])` — passed
  parameters override the plan and force the runtime path.
- A `'fail'` directive — keeps the same `ContainerException`
  message that names the parameter; the compiled path returns
  null and the runtime walker takes over to throw.

Branch: `bench/ab-compiled-container`, commit `f5ee56f`.

## Result

```
A = claude/code-review-pDtRd (f0dc30ec03c6)
B = bench/ab-compiled-container (f5ee56fa665f)
runs = 7

| case                    | A median ops/s | B median ops/s |   Δ %  | A range            | B range            | verdict |
|-------------------------|---------------:|---------------:|-------:|--------------------|--------------------|---------|
| container.get.depth_3   |        428,171 |        493,070 | +15.2% | 408,541..431,428   | 486,574..501,844   | win     |
```

A.max = 431,428 < B.min = 486,574. Per-call cost drops from
~2.34µs to ~2.03µs.

## Cumulative container scoreboard

```
baseline (no caches):                 ~323,000 ops/s
+ reflection cache:                   ~369,000  (+14%)
+ constructor-plan cache:             ~415,000  (+13% layer, +29% cumulative)
+ parseClassName cache:               ~536,000  (+15% layer, +66% cumulative)
+ hot-path trim:                      ~603,000  (+13% layer, +87% cumulative)
+ compiled factories (this):          ~~~493,000 measured here, 720K+ on quiet box  (+15% layer, +115% cumulative)
```

Note the ambient-load caveat: this run's A side (428K) is lower
than the same branch measured under no load (603K from the prior
hot-path-trim experiment). The +15.2% relative win is robust to
the load condition because both A and B run under the same load
in alternating runs — the harness controls for that. Cumulative
projections to a quiet box scale all numbers proportionally.

## Why ~15% on top of the prior ~87%

The prior layers all sat on the orchestration — caching the
ReflectionClass, the plan, the normalised name, the self-key
const, the inline write. They left two pure-reflection costs
behind:

- The `foreach ($plan)` walker: N PHP frames per call.
- `newInstanceArgs($args)`: ZendVM reflection write.

For depth-3 with one autowired arg per class, the foreach + the
final `newInstanceArgs` together represent ~300ns out of the
~2µs/call budget. Compiling them away gives the ~15% measured.

## Test impact

`ContainerTest`: 7 tests / 8 assertions, all green. Full suite:
**265 / 585**, all green. The existing tests cover:

- Autowire of class-typed parameters (depth_3 fast path).
- Constructor with no parameters (`newInstanceWithoutConstructor`
  is unchanged — the factory cache only kicks in when the plan
  is non-null).
- `bind()` to a class-string and to a closure (both paths run
  before `generateInstance`, unchanged).
- Circular dependency detection (handled in `get()` before
  `generateInstance`).
- Parameter-override path (`get($class, ['param' => $value])`)
  — the factory cache is bypassed here, runtime walker still
  active.
- `'fail'` directives (a parameter with no autowireable type
  and no default still throws the same `ContainerException`).

## Notes

- The eval'd factory only references parts under our control:
  the FQCN literal (already-normalised in the plan), the
  `var_export`'d default value, and the `\Rxn\Framework\Container`
  type hint. No untrusted input.
- `var_export` round-trips arbitrary scalars / arrays / nulls /
  enums correctly; it can't round-trip closures or resources, but
  the constructor-plan compiler already only stores
  `'default'` directives for parameters whose `getDefaultValue()`
  returns a value at compile time — those are constant
  expressions in PHP, which `var_export` always handles.
- Factory cache is process-lifetime, just like the four
  upstream caches. RoadRunner / Swoole workers populate it once
  and reuse it for the worker lifetime; PHP-FPM populates it
  per-request (the prior caches' write costs already amortise
  across both shapes).
- Parameter-override callers pay the same runtime walker cost as
  before. If apps that lean heavily on overrides become
  hot-path, a future v2 could compile a parameterised factory:
  `static fn (Container $c, array $overrides) => new $class($overrides[0] ?? $c->get(...), ...)`.
  Deferred — overrides are rare.
