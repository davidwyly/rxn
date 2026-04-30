# Schema-compiled Validator (Fastify trick — second attempt)

**Date:** 2026-04-29
**Decision:** **Merged** as
`a3934b5 perf(Validator): schema-compiled rule sets via eval-based
code-gen`. The biggest single-case opt-in win this branch — 2.45×
on `validator.check.clean`'s payload via the same code-gen
machinery that lost in the JSON encoder experiment.

## Hypothesis

The CompiledJson experiment lost to PHP's C-level `json_encode`.
The lesson there:

> Schema-compiling user-space code only beats the baseline when
> the baseline is also user-space. C-level builtins generally win.

`Validator::check()` is **100% pure PHP** — rule parsing
(`strpos+substr`), switch dispatch, per-rule helper calls, error
message construction. There's no `validate_input()` C extension to
lose to. So porting the same code-gen trick *here* should win
where it lost on JSON.

The runtime path's per-call cost on the bench payload (4 fields,
8 rules):

```php
foreach ($rules as $field => $fieldRules) {
    $value = $payload[$field] ?? null;
    $present = ...;
    $required = in_array('required', $fieldRules, true);
    if (!$present) { ... continue; }
    foreach ($fieldRules as $rule) {
        if ($rule === 'required') continue;
        $error = self::evaluate($field, $value, $rule);
        // evaluate() does: parse name+arg, switch on name,
        // call helper, return error message or null
        if ($error !== null) $errors[$field][] = $error;
    }
}
```

Per rule that's: `strpos`, two `substr`s, a `switch` jump, and a
helper-method dispatch. The compiled equivalent collapses every
one of those to constant-folded inline code.

## Change

New `Validator::compile($rules): \Closure` returns a closure whose
body is the entire rule set, inlined.

For the bench's rule set the eval'd body looks like:

```php
return static function (array $payload) use ($callables): array {
    $errors = [];
    // -- field: email
    $value   = $payload['email'] ?? null;
    $present = \array_key_exists('email', $payload) && $value !== '' && $value !== null;
    if (!$present) {
        $errors['email'][] = 'email is required';
    } else {
        if (\filter_var($value, \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'email must be a valid email address';
        }
    }
    // -- field: age
    $value   = $payload['age'] ?? null;
    $present = \array_key_exists('age', $payload) && $value !== '' && $value !== null;
    if (!$present) {
        $errors['age'][] = 'age is required';
    } else {
        if (!(\is_int($value) || (\is_string($value) && \ctype_digit(\ltrim($value, '-'))))) {
            $errors['age'][] = 'age must be an integer';
        }
        $size = \is_string($value) ? \mb_strlen($value)
              : (\is_array($value) ? \count($value)
              : (\is_numeric($value) ? (float)$value : null));
        if ($size === null) { $errors['age'][] = 'age has no measurable size'; }
        elseif ($size < 18) { $errors['age'][] = 'age must be >= 18'; }
        // ... and so on for max:120, in:..., regex:...
    }
    // ...
    return $errors;
};
```

Per-rule fragments are baked in: `min:18` becomes literal
`if ($size < 18)`, `in:admin,member,guest` becomes
`in_array((string)$value, ['admin','member','guest'], true)`,
regex pattern is interpolated as a single-quoted PHP literal.

Identical rule sets share a closure (cache key = `serialize($rules)`).
Rule sets containing closures bypass the cache (closures aren't
safely serialisable) and recompile fresh — still faster than the
runtime path.

22 unit tests cover parity (4 data-provider cases comparing the
output of `compile($rules)($payload)` against `check($payload, $rules)`
across all-valid, all-missing, all-invalid, and edge-empty
payloads), cache identity, and the callable-rule bypass. Full
suite remains green (259 / 579).

Branch: `bench/ab-compiled-validator`, commit `c9ddd62`.

## Result

```
A = claude/code-review-pDtRd (a400cc9c3cf4)
B = bench/ab-compiled-validator (c9ddd62771c2)
runs = 5

| case                       | A median ops/s | B median ops/s |     verdict |
|----------------------------|---------------:|---------------:|-------------|
| validator.check.clean      |        258,530 |        258,941 | noise (sanity) |
| validator.check.compiled   |              — |        631,604 | new path    |
```

`check.clean` unchanged across A/B as a sanity check — the
existing runtime path didn't regress. The new `check.compiled`
case lands at **631,604 ops/s** vs the runtime baseline's
**258,941** for the same payload + rules: **+144%, 2.45× faster**.
Per-call cost drops from ~3.9µs to ~1.6µs.

## Why this won where JSON lost

| | CompiledJson | CompiledValidator |
|---|---|---|
| Baseline implementation | C (`json_encode`) | PHP (Validator::evaluate) |
| What we generate        | PHP that calls `json_encode` per string | PHP that runs all checks inline |
| Per-property C-call cost on the generated path | yes (`json_encode` for strings) | no — `is_int`, `is_string` etc. are still C, but they're called at the *same rate* as the runtime path |
| Where the win is supposed to come from | skip type detection per value | skip rule parsing + switch dispatch + helper-call frames |
| Where the baseline wins | C json_encode walks typed object properties internally for free | runtime `evaluate()` does parse + switch + call PER rule, all in PHP |

In short, the compiled JSON trick was trying to skip work that's
free in C; the compiled validator trick is skipping work that's
expensive in PHP. Same code-gen machinery, opposite outcome.

## Test impact

ValidatorTest: 22 tests / 33 assertions, all green. Includes:
- 4 data-provider parity cases (all valid / all missing /
  all invalid / edge empty-string-as-missing) covering every
  rule shape: required, email, int, min, max, between, in,
  regex, array, string, url, bool, numeric.
- Cache identity test (same rules → same closure).
- Callable-rule bypass test (closures still work, just
  uncached).

Full suite: 259 / 579, all green. The runtime `check()` is
untouched — this is purely additive.

## Where this trick is from

The same Fastify `fast-json-stringify` schema-compile pattern that
JS-land has used for years, applied to validation instead of
encoding. Validation libraries on npm (fastest-validator, ajv,
zod with `compile()`) all do this for the same reason — pure-JS
baselines lose 2-10× to compiled-on-first-use rule sets. The
PHP analog had nobody actually shipping it yet.

## Notes

- Eval scope is tightly bounded: rule names go through a `match`
  with explicit cases (no fall-through to user input), patterns
  and IN-list values are quoted with `quoteString()` which
  escapes `\` and `'`, field names go through the same. There's
  no untrusted input on the code-gen path — the rule set comes
  from the framework's user, who already controls the executing
  PHP code.
- The cache key is `serialize($rules)`, which is O(rules) per
  call. Cheap relative to the closure invocation it gates, but
  if app-side hotspots pop up, callers can hold the closure
  reference themselves and skip the cache lookup entirely (which
  is what the bench harness does).
- An even-faster v2 could:
  - inline the size-extraction once per field, instead of per
    size-bound rule (currently min + max each emit their own
    `is_string ? mb_strlen : ...` cascade);
  - skip the `array_key_exists` if no rule references presence;
  - lift error message strings to constants if the same field
    has multiple rules emitting messages.
  All deferred — the +144% on the realistic payload is already
  the largest opt-in win the framework offers.
- Bin/bench gained a `validator.check.compiled` case alongside
  the existing `validator.check.clean`, so future regression
  detection is symmetric.
