# PsrAdapter factory cache (negative result)

**Date:** 2026-04-29
**Decision:** **Not merged.** Verdict was `noise`; preserved here
so the same idea isn't re-tried without a different mechanism in
mind.

## Hypothesis

`PsrAdapter::serverRequestFromGlobals()` builds a fresh
`Psr17Factory` and `ServerRequestCreator` on every call:

```php
public static function serverRequestFromGlobals(): ServerRequestInterface
{
    $factory = new Psr17Factory();
    $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
    return $creator->fromGlobals();
}
```

Both are stateless wrappers — caching them as static singletons
removes one allocation + one constructor call per request. With
`psr7.from_globals` running at ~88K ops/sec there should be room
for a measurable improvement.

## Change

```php
private static ?Psr17Factory $factory = null;
private static ?ServerRequestCreator $creator = null;

public static function serverRequestFromGlobals(): ServerRequestInterface
{
    if (self::$creator === null) {
        self::$factory = new Psr17Factory();
        self::$creator = new ServerRequestCreator(
            self::$factory, self::$factory, self::$factory, self::$factory
        );
    }
    return self::$creator->fromGlobals();
}
```

Branch: `bench/ab-psr-adapter-factory-cache`, commit
`4696b904db00`.

## Result

```
A = claude/code-review-pDtRd (4e7ad24ccaff)
B = bench/ab-psr-adapter-factory-cache (4696b904db00)
runs = 5

| case               | A median ops/s | B median ops/s |  Δ %  | A range           | B range           | verdict |
|--------------------|---------------:|---------------:|------:|-------------------|-------------------|---------|
| psr7.from_globals  |         72,145 |         73,021 | +1.2% | 63,418..73,826    | 72,500..73,625    | noise   |
```

+1.2% delta and overlapping ranges (A.max = 73,826 > B.min = 72,500).
Below the 5% variance floor.

## Why the hypothesis was wrong

The factory + creator construction is **not** the dominant cost in
`serverRequestFromGlobals()`. The actual work — `fromGlobals()`
itself — does header iteration, body stream wrapping, uploaded-file
normalisation, and URI parsing. A `new Psr17Factory()` and a
`new ServerRequestCreator()` are both empty-ish wrappers around
property assignment; the JIT and opcache absorb their cost.

Caching them eliminates two allocations, but the per-request work
still does ~10 KB of PSR-7 object construction inside `fromGlobals()`.
That's the mass — the wrappers are noise on top of it.

## Decision

Not merged. The change is "harmless" in the sense that nothing
breaks, but adding code that doesn't move the needle is still
pure complexity. Future revisits would need either:

- A different mechanism (e.g. caching the entire ServerRequest
  shape and only mutating headers/body — risky, gives up PSR-7
  immutability invariants), or
- A pooled-allocator approach for the URI / Stream objects
  inside `fromGlobals()`. That's where the actual mass is.

The branch `bench/ab-psr-adapter-factory-cache` is left in place
for the historical record; it can be deleted once enough time has
passed that nobody is going to re-investigate.
