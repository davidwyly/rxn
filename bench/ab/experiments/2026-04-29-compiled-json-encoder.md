# Schema-compiled JSON encoder (Fastify trick) — negative

**Date:** 2026-04-29
**Decision:** **Not merged.** Held in branch
`bench/ab-compiled-json-encoder` for the record. The most useful
negative result this branch has produced — confirms a category of
optimisation that *doesn't* port from JS to PHP.

## Hypothesis

Fastify's `fast-json-stringify` is responsible for a big chunk of
its lead over Express in JS-land benchmarks: at registration time
it walks the JSON Schema for a response shape and generates a
JavaScript function whose body is a flat sequence of string
concatenations with the per-property type-handling baked in. V8
JITs that flat function tightly; the type-detection-per-value cost
that `JSON.stringify($obj)` pays evaporates.

PHP has Reflection on typed public properties, `eval()` for
runtime code generation, and DTO classes with declared shapes —
all the pieces. So the same trick *should* port: a
`CompiledJson::for(Foo::class)` that emits per-class encoder
closures via `eval`'d source.

## Change

Two encoder forms, both eval-compiled per class:

**Per-instance:**

```php
$encode = CompiledJson::for(Product::class);
$encode($product);
// generated body:
//   return '{"id":'   . (string)$o->id
//        . ',"name":' . \json_encode($o->name, \JSON_UNESCAPED_SLASHES)
//        . ',"price":'. \json_encode($o->price)
//        . ',"active":'. ($o->active ? 'true' : 'false')
//        . '}';
```

**Batch (one closure invocation for the full list):**

```php
$encodeList = CompiledJson::forList(Product::class);
$encodeList($products);
// loop body inlines the same per-property fragments
```

Per-type encoding (full table in the source):

| Declared type | Generated expression |
|---|---|
| `int`     | `(string)$o->prop` |
| `bool`    | `($o->prop ? 'true' : 'false')` |
| `string`  | `json_encode($o->prop, JSON_UNESCAPED_SLASHES)` |
| `float`   | `json_encode($o->prop)` |
| `array`   | `json_encode($o->prop, JSON_UNESCAPED_SLASHES) ?: '[]'` |
| `?T`      | `($o->prop === null ? 'null' : <core>)` |
| object / union / untyped | `json_encode($o->prop, ...) ?: 'null'` |

8 unit tests cover scalar mix, nullable, escaping, empty class,
arrays, static-property exclusion, the `encode()` convenience, and
the per-class cache.

Branch: `bench/ab-compiled-json-encoder`.

## Result

```
case                                  ops/sec      Δ vs baseline
-----------------------------------  ----------- ----------------
json.encode_dto.single.baseline       1,802,827    —
json.encode_dto.single.compiled       1,168,676   −35%
json.encode_dto.list.baseline            19,211    —
json.encode_dto.list.compiled            10,941   −43%
json.encode_dto.list.compiled_batch      11,954   −37%
```

All three variants are slower than baseline. The batch encoder
helps marginally (one closure invocation instead of 100) but is
still well behind plain `json_encode($array)`.

## Why it loses in PHP when it wins in JS

PHP's `json_encode` is implemented in C as part of the `json`
extension. For a typed object with public properties, it walks the
property hashtable in one pass and emits the JSON directly — the
"per-value type detection" cost we set out to skip is essentially
free at the C level.

JavaScript's `JSON.stringify` is part of V8's runtime but isn't a
hand-tuned C primitive in the same sense — and even where it is,
the overhead of marshalling JS values to native and back is real.
Generating a JIT-friendly JavaScript function that walks the
known shape directly skips that marshalling. PHP doesn't have an
analogous boundary to skip — `eval`'d PHP runs in the same VM as
the rest of our code.

Concretely, our compiled body has to call `json_encode` *anyway*
for strings (to get correct escaping), and once you're back in C
land for those calls the closure-invocation overhead has already
cost more than the type-detection skip saves.

## Generalisable lesson

> **Schema-compiling user-space code only beats the baseline when
> the baseline is also user-space.** If the baseline is a tuned C
> extension (json_encode, preg_match, str_replace, hash_*),
> generated PHP can't catch up.

This shaped the next experiment: pivot the same code-gen idea to
the `Validator`, where the baseline (rule-parsing + switch
dispatch + per-rule helper calls) is entirely in PHP.

## Test impact

CompiledJsonTest: 8 tests / 8 assertions, all green. Full suite
remained at 253 / 573 with the branch's changes added.

## Notes

- The eval'd source is built only from reflected names and types,
  both controlled by the framework's own Reflection API. Property
  names are guarded by an identifier-shape regex before
  interpolation, so no untrusted input reaches `eval`.
- The exploration was kept on `bench/ab-compiled-json-encoder`
  rather than rewritten away, because the eval-based code-gen
  scaffolding (`quote()`, `sanitizeName()`, the closure-build
  pattern) is exactly the shape needed for the validator
  compilation experiment that followed.
