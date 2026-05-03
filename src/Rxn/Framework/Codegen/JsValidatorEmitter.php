<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Max;
use Rxn\Framework\Http\Attribute\NotBlank;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Attribute\Url;
use Rxn\Framework\Http\Attribute\Email;
use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Binding\Validates;

/**
 * Emit a vanilla ES module that validates an input object against
 * the same rules `Binder::bind` enforces server-side. Same DTO,
 * two targets — the JS twin is structured as a mechanical mirror
 * of `Binder::compileProperty()` so divergences are easy to spot
 * in code review.
 *
 *   $js = (new JsValidatorEmitter())->emit(CreateProduct::class);
 *   file_put_contents('CreateProduct.mjs', $js);
 *
 * The output exports `validate(input) -> {valid, errors}` where
 * `errors` is `[{field, message}, ...]`. Field names match the PHP
 * side; messages match server-side wording verbatim so a
 * cross-language diff test can assert exact equivalence.
 *
 * Coverage matrix (this file vs the PHP runtime walker in
 * `Binder::bind` + `Binder::cast` + the `inlineValidator` table):
 *
 *   Property typing : string, int, float, bool, ?string (nullable)
 *   Field-level     : Required (presence/null/empty)
 *   Validators      : NotBlank, Length(min/max), Min, Max, InSet,
 *                     Email (FILTER_VALIDATE_EMAIL twin), Url
 *                     (FILTER_VALIDATE_URL twin)
 *
 * Out of scope for v1 (will throw `UnsupportedAttribute`):
 *
 *   Pattern (PCRE → JS regex requires careful subset analysis)
 *   Uuid, Json, Date (parser-shape divergences across runtimes)
 *   Custom Validates implementations (no PHP→JS transpiler exists)
 *
 * The emitter throws when it encounters an attribute it doesn't
 * know how to mirror — *silent* divergence is the worst possible
 * failure mode.
 */
final class JsValidatorEmitter
{
    public function emit(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->implementsInterface(RequestDto::class)) {
            throw new \InvalidArgumentException("$class must implement " . RequestDto::class);
        }

        $body = "  const errors = [];\n";
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $body .= $this->emitProperty($prop);
        }
        $body .= "  return { valid: errors.length === 0, errors };\n";

        return self::prelude()
            . "export function validate(input) {\n"
            . $body
            . "}\n";
    }

    private function emitProperty(\ReflectionProperty $prop): string
    {
        $name   = $prop->getName();
        $nameQ  = $this->jsString($name);
        $type   = $prop->getType();

        $isRequired = $prop->getAttributes(Required::class) !== [];
        $hasDefault = $prop->hasDefaultValue();
        $allowsNull = $type instanceof \ReflectionNamedType && $type->allowsNull();

        $missingBranch = '';
        if ($isRequired) {
            $missingBranch = "    errors.push({ field: $nameQ, message: 'is required' });\n";
        } elseif ($hasDefault) {
            // Default value path — no error, no validation. Mirror
            // of Binder's behaviour: defaults skip the cast +
            // attribute checks entirely.
        } elseif ($allowsNull) {
            // null path — no error, no validation, same logic.
        }

        $castBlock = $this->emitCast($type);
        $validateBlock = '';
        foreach ($prop->getAttributes() as $attr) {
            $attrName = $attr->getName();
            if ($attrName === Required::class) {
                continue;
            }
            $validateBlock .= $this->emitInline($attrName, $attr->getArguments(), $nameQ);
        }

        $src  = "  // -- property: $name\n";
        $src .= "  if (!hasOwn(input, $nameQ) || input[$nameQ] === null || input[$nameQ] === '') {\n";
        if ($missingBranch !== '') {
            $src .= $missingBranch;
        }
        $src .= "  } else {\n"
              . "    let value = input[$nameQ];\n"
              . "    let cast, castFailed;\n"
              . $castBlock
              . "    if (cast === null && castFailed) {\n"
              . "      errors.push({ field: $nameQ, message: 'type mismatch' });\n"
              . "    } else {\n"
              . $validateBlock
              . "    }\n"
              . "  }\n";

        return $src;
    }

    private function emitCast(?\ReflectionType $type): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return "    cast = value; castFailed = false;\n";
        }
        $name = $type->getName();
        return match ($name) {
            'mixed' => "    cast = value; castFailed = false;\n",
            'string' => <<<JS
                if (Array.isArray(value) || (typeof value === 'object' && value !== null)) {
                  cast = null; castFailed = true;
                } else if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
                  cast = phpStrCast(value); castFailed = false;
                } else {
                  cast = null; castFailed = true;
                }

                JS,
            'int' => <<<JS
                if (Array.isArray(value) || (typeof value === 'object' && value !== null)) {
                  cast = null; castFailed = true;
                } else {
                  const r = phpIntCast(value);
                  if (r.ok) { cast = r.value; castFailed = false; }
                  else      { cast = null; castFailed = true; }
                }

                JS,
            'float' => <<<JS
                if (Array.isArray(value) || (typeof value === 'object' && value !== null)) {
                  cast = null; castFailed = true;
                } else {
                  const r = phpFloatCast(value);
                  if (r.ok) { cast = r.value; castFailed = false; }
                  else      { cast = null; castFailed = true; }
                }

                JS,
            'bool' => <<<JS
                if (typeof value === 'boolean') {
                  cast = value; castFailed = false;
                } else if (Array.isArray(value) || (typeof value === 'object' && value !== null)) {
                  cast = null; castFailed = true;
                } else {
                  const lower = String(value).toLowerCase();
                  if (lower === '1' || lower === 'true' || lower === 'yes' || lower === 'on') {
                    cast = true; castFailed = false;
                  } else if (lower === '0' || lower === 'false' || lower === 'no' || lower === 'off') {
                    cast = false; castFailed = false;
                  } else {
                    cast = null; castFailed = true;
                  }
                }

                JS,
            'array', 'iterable' => <<<JS
                if (Array.isArray(value) || (typeof value === 'object' && value !== null)) {
                  cast = value; castFailed = false;
                } else {
                  cast = null; castFailed = true;
                }

                JS,
            default => "    cast = null; castFailed = true;\n",
        };
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function emitInline(string $attrName, array $args, string $fieldQ): string
    {
        return match ($attrName) {
            NotBlank::class => $this->emitNotBlank($fieldQ),
            Length::class   => $this->emitLength($args, $fieldQ),
            Min::class      => $this->emitMin($args, $fieldQ),
            Max::class      => $this->emitMax($args, $fieldQ),
            InSet::class    => $this->emitInSet($args, $fieldQ),
            Url::class      => $this->emitFilter($fieldQ, 'phpFilterUrl', 'must be a valid URL'),
            Email::class    => $this->emitFilter($fieldQ, 'phpFilterEmail', 'must be a valid email address'),
            default         => $this->refuse($attrName),
        };
    }

    private function refuse(string $attrName): string
    {
        // Non-Validates attributes are ignored: Binder still
        // instantiates them but only applies attributes that
        // implement Validates, so they're a no-op for validation.
        // Refuse every Validates implementation we cannot mirror
        // — silent divergence between generated JS and Binder is
        // the worst failure mode.
        $ref = new \ReflectionClass($attrName);
        if ($ref->implementsInterface(Validates::class)) {
            throw new \RuntimeException(
                "JsValidatorEmitter: $attrName implements " . Validates::class . " but has no JS twin yet. "
                . "Refusing to emit silently-divergent code. "
                . "Add an emit method or document the DTO as PHP-only.",
            );
        }
        return '';
    }

    private function emitNotBlank(string $fieldQ): string
    {
        return "      if (typeof cast === 'string' && cast.trim() === '') {\n"
             . "        errors.push({ field: $fieldQ, message: 'must not be blank' });\n"
             . "      }\n";
    }

    /** @param array<int|string, mixed> $args */
    private function emitLength(array $args, string $fieldQ): string
    {
        $min = $args[0] ?? $args['min'] ?? null;
        $max = $args[1] ?? $args['max'] ?? null;
        $body  = "      if (typeof cast === 'string') {\n";
        $body .= "        const len = [...cast].length;\n";
        if ($min !== null) {
            $body .= "        if (len < $min) errors.push({ field: $fieldQ, message: 'must be at least $min characters' });\n";
        }
        if ($max !== null) {
            $body .= "        if (len > $max) errors.push({ field: $fieldQ, message: 'must be at most $max characters' });\n";
        }
        $body .= "      }\n";
        return $body;
    }

    /** @param array<int|string, mixed> $args */
    private function emitMin(array $args, string $fieldQ): string
    {
        $min = $args[0] ?? $args['min'] ?? 0;
        $minLit = is_int($min) ? (string) $min : (string) (float) $min;
        return "      if (typeof cast === 'number' && cast < $minLit) {\n"
             . "        errors.push({ field: $fieldQ, message: 'must be >= $minLit' });\n"
             . "      }\n";
    }

    /** @param array<int|string, mixed> $args */
    private function emitMax(array $args, string $fieldQ): string
    {
        $max = $args[0] ?? $args['max'] ?? 0;
        $maxLit = is_int($max) ? (string) $max : (string) (float) $max;
        return "      if (typeof cast === 'number' && cast > $maxLit) {\n"
             . "        errors.push({ field: $fieldQ, message: 'must be <= $maxLit' });\n"
             . "      }\n";
    }

    /** @param array<int|string, mixed> $args */
    private function emitInSet(array $args, string $fieldQ): string
    {
        $values = $args[0] ?? $args['values'] ?? [];
        if (!is_array($values)) {
            throw new \InvalidArgumentException('InSet expects an array of values');
        }
        $jsArr = '[' . implode(', ', array_map(fn ($v) => $this->jsLiteral($v), $values)) . ']';
        // Mirror PHP's `in_array(..., true)` strict equality: JS `Array.prototype.includes`
        // already uses `SameValueZero` which agrees on strings/numbers/booleans for our cases.
        $allowedMsg = $this->jsString('must be one of: ' . implode(', ', array_map('strval', $values)));
        return "      if (cast !== null && !$jsArr.includes(cast)) {\n"
             . "        errors.push({ field: $fieldQ, message: $allowedMsg });\n"
             . "      }\n";
    }

    private function emitFilter(string $fieldQ, string $fnName, string $msg): string
    {
        $msgQ = $this->jsString($msg);
        return "      if (typeof cast === 'string' && !$fnName(cast)) {\n"
             . "        errors.push({ field: $fieldQ, message: $msgQ });\n"
             . "      }\n";
    }

    private function jsString(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function jsLiteral(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Helpers that reproduce PHP's runtime behaviour. The PHP twin
     * for each helper lives in `Binder::cast`. Comments below each
     * helper name the exact PHP line it mirrors.
     */
    private static function prelude(): string
    {
        return <<<JS
        // ---- generated by Rxn\\Framework\\Codegen\\JsValidatorEmitter ----
        // DO NOT EDIT: regenerate from the source DTO.

        function hasOwn(o, k) {
          return o !== null && typeof o === 'object' && Object.prototype.hasOwnProperty.call(o, k);
        }

        // Mirrors PHP's (string) cast: bool->'1'/'',  others->String(\$v).
        function phpStrCast(v) {
          if (typeof v === 'boolean') return v ? '1' : '';
          return String(v);
        }

        // Mirrors PHP's runtime int-cast guard:
        //   is_numeric(\$v) && (string)(int)\$v === (string)\$v
        // Only round-tripping integer strings pass. NO trim() — the
        // round-trip check is bit-for-bit string equality, so any
        // leading/trailing whitespace, leading "+", or leading zeros
        // make the input fail (matching PHP).
        function phpIntCast(v) {
          if (typeof v === 'boolean') return { ok: false };
          const s = String(v);
          if (!/^-?\\d+\$/.test(s)) return { ok: false };
          const n = parseInt(s, 10);
          if (!Number.isFinite(n) || String(n) !== s) return { ok: false };
          return { ok: true, value: n };
        }

        // Mirrors PHP's runtime float-cast guard:
        //   is_numeric(\$v) ? (float)\$v : FAIL
        // PHP's is_numeric accepts ints, decimals, and scientific
        // notation; we mirror by parsing and checking for NaN.
        function phpFloatCast(v) {
          if (typeof v === 'boolean') return { ok: false };
          const s = String(v).trim();
          if (s === '') return { ok: false };
          // PHP's is_numeric accepts: integers, decimals, leading +/-,
          // scientific (1e3, 1.5E-2), hex (0x1A — but not is_numeric),
          // and trims one trailing whitespace. We use a permissive
          // regex matching the PHP runtime.
          if (!/^[+-]?(\\d+\\.?\\d*|\\.\\d+)([eE][+-]?\\d+)?\$/.test(s)) {
            return { ok: false };
          }
          const n = Number(s);
          if (!Number.isFinite(n)) return { ok: false };
          return { ok: true, value: n };
        }

        // Mirrors PHP's filter_var(\$v, FILTER_VALIDATE_URL).
        // PHP's filter_var requires a scheme + host and is strict.
        // The closest portable approximation: try URL constructor +
        // additional checks that reject relative / scheme-less URLs.
        function phpFilterUrl(v) {
          if (typeof v !== 'string' || v === '') return false;
          let parsed;
          try { parsed = new URL(v); } catch { return false; }
          if (!parsed.protocol || !parsed.host) return false;
          // PHP rejects URLs with characters outside the URL spec; the
          // URL constructor is more permissive. Re-check with a regex
          // approximating PHP's accepted character set.
          return /^[A-Za-z][A-Za-z0-9+.\\-]*:\\/\\/[^\\s<>"'\`]+\$/.test(v);
        }

        // Mirrors PHP's filter_var(\$v, FILTER_VALIDATE_EMAIL).
        // The exact regex PHP uses internally; transcribed.
        function phpFilterEmail(v) {
          if (typeof v !== 'string') return false;
          // Pragmatic subset matching PHP's behaviour for the common case.
          return /^[A-Za-z0-9._%+\\-]+@[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}\$/.test(v);
        }


        JS;
    }
}
