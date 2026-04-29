# ActiveRecord::hydrate inline loop (no per-row closure)

**Date:** 2026-04-29
**Decision:** **Merged** as
`a15428e perf(ActiveRecord): inline hydrate loop, no per-row closure`.
The biggest single-change percentage win on a non-router case
this branch has produced.

## Hypothesis

`hydrate()` was doing
`array_map(fn (array $r) => $class::fromRow($r), $rows)`. Per row
that's:

1. A closure invocation (`fn ... =>`)
2. A static-method dispatch through `$class::fromRow($r)`
3. Inside `fromRow`: `new static()` + a property write

Three frames of overhead per row, where the actual work is one
`new` + one assignment. Inlining the body into a flat `foreach`
collapses 1 + 2 + the `fromRow` frame into a single loop step the
JIT can reason about.

## Change

```php
public static function hydrate(array $rows, string $class): array
{
    if (!is_subclass_of($class, self::class)) {
        throw new \InvalidArgumentException("$class is not an ActiveRecord");
    }
    $out = [];
    foreach ($rows as $r) {
        $instance = new $class();
        $instance->attributes = $r;
        $out[] = $instance;
    }
    return $out;
}
```

Visibility: `$attributes` is `protected` on `ActiveRecord`. Writing
it on subclass instances from within `ActiveRecord::hydrate()`
(also a static method of the base class) is fine — protected
members are accessible from any method of the declaring class
against instances of the class or its subclasses.

`fromRow()` is left in place — it's still the right entry point
for "I have one row, give me one record" callers; it's just no
longer on the bulk path.

Branch: `bench/ab-activerecord-hydrate-inline`, commit `2c862f9`.

## Result

```
A = claude/code-review-pDtRd (3597b8145837)
B = bench/ab-activerecord-hydrate-inline (2c862f9950e5)
runs = 5

| case                        | A median ops/s | B median ops/s |    Δ %   | A range            | B range            | verdict |
|-----------------------------|---------------:|---------------:|---------:|--------------------|--------------------|---------|
| active_record.hydrate_100   |         99,562 |        187,283 |  +88.1%  | 96,310..103,207    | 174,306..189,246   | win     |
```

100 rows hydrated 1.88× faster. Per-row cost drops from ~10µs to
~5.3µs. Ranges fully separated; A.max = 103,207 < B.min = 174,306.

## Why the gap was so large

PHP closures (and the more recent arrow-fn shorthand) are real
allocations — each invocation goes through `Closure::__invoke`,
which is a userspace dispatch even when the body is one line. On
a 100-row hydrate that's 100 closure-invocation frames + 100
static-method dispatches + 100 `new`+write pairs. The inlined
loop has one `foreach` header + 100 `new`+write pairs, full stop.

The 88% number is consistent with profiling other PHP frameworks
that have made the same swap: `array_map` with a closure tends to
cost ~2× a plain loop for tight per-element bodies.

## Test impact

`ActiveRecordTest`: 12 tests / 21 assertions, all green. The
existing tests cover hydrate against multiple subclass shapes,
including the `hydrate(...)` → `__get` → primary-key / column
attribute access path that depends on `$attributes` being
populated correctly.

## Notes

- The optimisation only affects `hydrate()`; single-row
  `find()` still goes through `fromRow()` (one row, no batch
  overhead matters).
- No allocation-count change per row — still one new + one row
  array reference. The win is purely in dispatch overhead.
- Depends on the assumption that subclasses of ActiveRecord have
  a parameter-less constructor (i.e., `new $class()` works).
  That's already an invariant of the existing `fromRow()` (which
  does `new static()`); if a subclass adds required constructor
  args it would have broken `fromRow` too.
