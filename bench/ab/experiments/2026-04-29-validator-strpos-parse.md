# Validator rule-parse: strpos/substr instead of explode/array_pad

**Date:** 2026-04-29
**Decision:** **Merged** as `0c?????
perf(Validator): strpos/substr rule parse, no per-rule array`.
(Cherry-picked from `bench/ab-validator-strpos-parse`.)

## Hypothesis

Each rule string parse in `Validator::evaluate` was:

```php
[$name, $arg] = array_pad(explode(':', (string)$rule, 2), 2, null);
```

That allocates a 2-element array per rule. A typical
`Validator::check` call walks 15–20 rules across a few fields, so
the throwaway-array count per check is also 15–20. `strpos +
substr` does the same parse with no allocation.

## Change

```php
$rule  = (string) $rule;
$colon = strpos($rule, ':');
if ($colon === false) {
    $name = $rule;
    $arg  = null;
} else {
    $name = substr($rule, 0, $colon);
    $arg  = substr($rule, $colon + 1);
}
```

Semantics preserved: keyword rules (no colon) still produce
`$arg=null`; colon-bearing rules still split at the first colon
with the remainder kept as a single string arg (so
`'between:1,10'` still parses to name=`between`, arg=`1,10`).

Branch: `bench/ab-validator-strpos-parse`, commit `1e565de`.

## Result

```
A = claude/code-review-pDtRd (3597b8145837)
B = bench/ab-validator-strpos-parse (1e565de9016f)
runs = 5

| case                    | A median ops/s | B median ops/s |   Δ %  | A range            | B range            | verdict |
|-------------------------|---------------:|---------------:|-------:|--------------------|--------------------|---------|
| validator.check.clean   |        328,200 |        354,897 |  +8.1% | 327,073..337,733   | 346,897..360,264   | win     |
```

A.max = 337,733 < B.min = 346,897. Clean win across runs.

## Test impact

`ValidatorTest`: 16 tests / 26 assertions, all green. The tests
exercise every rule shape (keyword, `name:arg`, `name:arg,arg`,
callable) so the new parse path is covered.

## Notes

- The same micro-pattern (`explode → strpos+substr`) shows up
  inside the `between` rule too: `[$lo, $hi] =
  array_map('floatval', explode(',', $arg, 2));`. Left unchanged
  for this experiment — the `between` rule isn't in the bench
  payload, so any measurement of that micro-change would be
  inferred rather than measured. A future experiment can do it
  alongside a rule that exercises `between`.
- For apps that call `Validator::check` per request (not a hot
  path for most Rxn apps, since DTO binding handles the typical
  case), the +8% compounds. For apps using the DTO `Binder`
  instead, `Validator` isn't on the critical path at all.
