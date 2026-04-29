# Schema-compiled Binder — DTO hydration code-gen

**Date:** 2026-04-29
**Decision:** **Merged** as
`d284bf5 perf(Binder): schema-compiled DTO hydration via eval-based
code-gen`. **The largest single-case opt-in win on this branch —
6.42× on `binder.bind` for the same payload + DTO shape.** Stacks
on top of the lessons from CompiledJson (negative) and
CompiledValidator (+2.45×).

## Hypothesis

The CompiledValidator experiment confirmed: schema-compilation
beats the runtime path when the runtime path is dominated by
*pure-PHP* dispatch overhead. `Binder::bind()` is the most
reflection-heavy hot path in the framework — every call does:

- `new \ReflectionClass($class)` — full reflection materialisation
- `$ref->newInstanceWithoutConstructor()`
- `$ref->getProperties(IS_PUBLIC)` — materialises a fresh
  `ReflectionProperty[]`
- per property:
  - `$prop->getName()` (PHP method dispatch into C)
  - `$prop->getType()` (ditto)
  - `$prop->getAttributes(Required::class)` (allocates
    `ReflectionAttribute[]`)
  - `$prop->getAttributes()` (allocates again, this time unfiltered)
  - per attribute:
    - **`$attr->newInstance()` — instantiates the attribute class
      every bind() call**
    - `$instance->validate($cast)` (one PHP frame)
  - `$prop->setValue($dto, $cast)` (Reflection write, not a direct
    property assignment)

For a 5-property DTO with one attribute per property that's ~30+
Reflection-flavoured method calls per `bind()`. The `newInstance()`
calls are the worst offenders — each one allocates a fresh
`Min(0)` / `Length(min:1, max:100)` / etc. that the runtime path
discards an instant later.

Compilation eliminates literally all of it. The reflection runs
once, at compile, and the result is baked into straight-line PHP.

## Change

New `Binder::compileFor($class): \Closure` returns a closure
`fn(array $bag): RequestDto` that hydrates + validates without any
runtime Reflection.

For the bench's DTO:

```php
final class BenchProductBindDto implements RequestDto {
    #[Required] #[Length(min: 1, max: 100)]
    public string $name;
    #[Required] #[Min(0)]
    public int $price;
    public bool $active = true;
    #[Length(max: 500)]
    public ?string $description = null;
    #[InSet(['draft', 'published', 'archived'])]
    public string $status = 'draft';
}
```

The eval'd body looks roughly like:

```php
return static function (array $bag) use ($validators): BenchProductBindDto {
    $errors = [];
    $dto = new \BenchProductBindDto();

    // -- property: name
    if (!\array_key_exists('name', $bag) || $bag['name'] === null || $bag['name'] === '') {
        $errors[] = ['field' => 'name', 'message' => 'is required'];
    } else {
        $value = $bag['name'];
        if (\is_array($value)) { $cast = null; $castFailed = true; }
        elseif (\is_scalar($value)) { $cast = (string)$value; $castFailed = false; }
        else { $cast = null; $castFailed = true; }
        if ($cast === null && $castFailed) {
            $errors[] = ['field' => 'name', 'message' => 'type mismatch'];
        } else {
            // Length(min: 1, max: 100) — inlined
            if (\is_string($cast)) {
                $len = \mb_strlen($cast);
                if ($len < 1) {
                    $errors[] = ['field' => 'name', 'message' => 'must be at least 1 characters'];
                }
                if ($len > 100) {
                    $errors[] = ['field' => 'name', 'message' => 'must be at most 100 characters'];
                }
            }
            $dto->name = $cast;  // direct write, no Reflection::setValue
        }
    }
    // ... and so on for price, active, description, status ...
    if ($errors !== []) {
        throw new \Rxn\Framework\Http\Binding\ValidationException($errors);
    }
    return $dto;
};
```

Per the inlining table:

| Attribute | How it's compiled |
|---|---|
| `Required` | presence check at the field level — no fragment emitted |
| `Min(N)` | `if ((is_int($v) || is_float($v)) && $v < N) $errors[] = ...` |
| `Max(N)` | mirror image |
| `Length(min, max)` | `if (is_string($v)) { $len = mb_strlen($v); ... }` with each bound emitted only when set |
| `Pattern(regex)` | `if (is_string($v) && preg_match('regex', $v) !== 1) $errors[] = ...` |
| `InSet(values)` | `if (!in_array($v, [...], true)) $errors[] = ...` with the values literal-baked via `var_export` |
| **other** `Validates` impls | instantiated **once at compile** and dispatched via `$validators[$idx]->validate($cast)` from `use ($validators)` — `newInstance()` per call goes away even for app-defined attributes |

Default values are baked via `var_export($prop->getDefaultValue(), true)`,
so the missing-but-defaulted branch is `$dto->name = 'default-slug';`
with the literal inlined.

Identical `$class` → same closure (process-lifetime cache, keyed
by class name).

5 data-provider parity tests verify compiled output matches
runtime output for **both** hydrated state (when valid) and the
collected error sets (when not), across all-valid /
optional-default / all-invalid / boundary / nullable cases. Plus
a cache-identity test.

Branch: `bench/ab-compiled-binder`, commit `b8c1760`.

## Result

```
Same-branch comparison (B = bench/ab-compiled-binder, runs = 7):

| case                    | median ops/s | range                  | per-call cost |
|-------------------------|-------------:|------------------------|---------------|
| binder.bind.runtime     |      143,891 | 142,426..144,542       | ~6.95µs       |
| binder.bind.compiled    |      923,452 | 876,185..934,752       | ~1.08µs       |

speedup: 923,452 / 143,891 = 6.42×
```

(The A side of the A/B shows 0 for both cases because they don't
exist on the integration branch yet — `bench/ab.php` only knows
how to compare cases that exist on both sides. The
same-branch comparison on B is what's diagnostic.)

The runtime path is unchanged — both bench cases reuse the same
`Binder::bind()` for `runtime` and only the new `compileFor()`
path for `compiled`. Per-call cost drops from ~6.95µs to ~1.08µs.

## Why this is the biggest win on the branch

The runtime `bind()` is the most reflection-heavy hot path in the
framework. CompiledValidator removed *parsing + switch dispatch +
helper-call frames* — pure PHP dispatch overhead, ~2.45×.
CompiledBinder removes *all of that plus the ReflectionClass /
ReflectionProperty / ReflectionAttribute machinery and the
attribute `newInstance()` per call*. The latter is the dominant
cost — instantiating a fresh `Length(min:1, max:100)` per
property per call costs hundreds of nanoseconds, and we were
doing it 5–10 times per request.

Per the lesson distilled from the CompiledJson failure:

> Schema-compiling user-space code only beats the baseline when
> the baseline is also user-space.

`Binder::bind` IS user-space (just one orchestrating tons of
reflection through PHP method frames). The cast checks
(`is_string`, `mb_strlen`, etc.) are C — but they're called at
the same rate on both paths. Everything saved is the
PHP-level dispatch overhead.

## Test impact

Full suite: **265 tests / 586 assertions**, all green. Of those,
**+6 new tests** for `compileFor`:

- 5 data-provider parity cases comparing
  `compileFor($class)($bag)` against `bind($class, $bag)` for
  both successful hydration (`assertEquals` on the full DTO
  graph) and `ValidationException` error sets (`assertSame` on
  the collected `[['field' => ..., 'message' => ...], ...]`).
- 1 cache-identity test (same class → same closure).

All 13 pre-existing BinderTest cases continue to pass against the
unchanged runtime path.

## Cumulative scoreboard for the binder

```
baseline (runtime path):              ~143,000 ops/s  ~7.0µs/bind
+ compileFor (new path):              ~923,000 ops/s  ~1.1µs/bind
speedup vs runtime:                   6.42×
```

## Notes

- Eval scope is tightly bounded. Property names go through
  `ensureIdentifier()` (PHP identifier shape regex). String
  literals (default values, regex patterns, IN-set values, error
  messages) are all interpolated via `quoteString()` (single-
  quote escape) or `var_export()` (PHP literal). There's no
  untrusted input on the code-gen path — class definitions are
  controlled by the framework's user, who already controls the
  executing PHP code.
- App-defined `Validates` attributes still work on the compiled
  path. They aren't inlined (the framework only knows how to
  inline its own five attribute types) but they're instantiated
  *once at compile time* and captured by the closure via
  `use ($validators)`, so the per-call `newInstance()` cost
  goes away for them too.
- For attributes that aren't validators (markers like a
  hypothetical `#[Hidden]`), the compiled path skips them
  entirely, just as the runtime path's `if (!$instance instanceof
  Validates) continue;` does.
- `Binder::bind()` is unchanged — the compiled path is opt-in,
  so apps that want the existing reflection-driven behaviour
  (e.g. for ad-hoc DTO classes registered late in the request
  lifecycle) keep it. Apps that bind the same DTO every request
  hold a closure reference and skip the cache lookup entirely.
- Future v2 ideas:
  - Pre-resolve `Required::class` attribute presence into a
    bitmap at compile so the field-level `array_key_exists`
    branch can choose between three specialised emit paths
    (required, has-default, nullable).
  - Hoist string error messages (e.g. `'is required'`) into
    `use(...)` constants if the same message appears across many
    fields.
  - Generate per-DTO `Hydrator::hydrate*()` *methods* on a
    generated subclass, so `instanceof DtoFoo` checks don't
    need a closure indirection. Bigger lift, modest expected
    additional gain.
