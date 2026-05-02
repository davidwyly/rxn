<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Binding;

use Rxn\Framework\Http\Attribute\Date;
use Rxn\Framework\Http\Attribute\Email;
use Rxn\Framework\Http\Attribute\EndsWith;
use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Json;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Max;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\NotBlank;
use Rxn\Framework\Http\Attribute\Pattern;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Attribute\StartsWith;
use Rxn\Framework\Http\Attribute\Url;
use Rxn\Framework\Http\Attribute\Uuid;

/**
 * Hydrate a `RequestDto` from the merged request bag (query +
 * body) and run property-level validation attributes against the
 * cast values. Fails loudly with a `ValidationException` carrying
 * every error at once — rather than short-circuiting on the first
 * — so clients can correct a whole form in one round trip.
 *
 *   $dto = Binder::bind(CreateProductRequest::class);
 *
 * Public typed properties are populated positionally by name
 * from the request. Request values come in as strings (PHP) or
 * arrays; the Binder coerces to the property's declared type.
 * Missing fields with a default expression or nullable type pass
 * through; missing required fields fail.
 */
final class Binder
{
    /**
     * @template T of RequestDto
     * @param class-string<T> $class
     * @param array<string, mixed>|null $source override for the
     *        request bag — tests pass a fixture; production passes
     *        null and we read the superglobals.
     * @return T
     */
    public static function bind(string $class, ?array $source = null): RequestDto
    {
        $bag = $source ?? self::gatherBag();
        $ref = new \ReflectionClass($class);
        $dto = $ref->newInstanceWithoutConstructor();
        if (!$dto instanceof RequestDto) {
            throw new \InvalidArgumentException("$class must implement " . RequestDto::class);
        }

        $errors = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            $type = $prop->getType();
            $hasValue = array_key_exists($name, $bag);
            $isRequired = $prop->getAttributes(Required::class) !== [];

            if (!$hasValue || $bag[$name] === null || $bag[$name] === '') {
                if ($isRequired) {
                    $errors[] = ['field' => $name, 'message' => 'is required'];
                    continue;
                }
                if ($prop->hasDefaultValue()) {
                    $prop->setValue($dto, $prop->getDefaultValue());
                } elseif ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
                    $prop->setValue($dto, null);
                }
                continue;
            }

            $cast = self::cast($bag[$name], $type);
            if ($cast === self::CAST_FAIL) {
                $errors[] = ['field' => $name, 'message' => 'type mismatch'];
                continue;
            }

            foreach ($prop->getAttributes() as $attr) {
                $instance = $attr->newInstance();
                if (!$instance instanceof Validates) {
                    continue;
                }
                $msg = $instance->validate($cast);
                if ($msg !== null) {
                    $errors[] = ['field' => $name, 'message' => $msg];
                }
            }

            $prop->setValue($dto, $cast);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $dto;
    }

    /**
     * Cache of eval-compiled binders keyed by class name.
     *
     * @var array<class-string, \Closure(array): RequestDto>
     */
    private static array $compiledCache = [];

    /**
     * Compile a binder for `$class` into a closure that hydrates +
     * validates an instance from `$bag` with no runtime Reflection.
     *
     * Same code-gen pattern that won 2.45× on `Validator::compile`,
     * applied to `Binder::bind`. The runtime path's per-call cost
     * is dominated by reflection (`new \ReflectionClass`,
     * `getProperties`, `getName`/`getType`/`getAttributes` per
     * property, and `$attr->newInstance()` for every validation
     * attribute on every call). The compiled closure does the
     * reflection once at compile time and bakes the result into
     * straight-line PHP:
     *
     *   - property names → bag keys, baked as literals
     *   - declared types → specialised cast expressions
     *   - default values → baked via var_export
     *   - validation attributes (Min/Max/Length/Pattern/InSet) →
     *     inlined as direct comparisons
     *   - direct property writes (`$dto->name = $cast`), no
     *     `ReflectionProperty::setValue`
     *
     * Unknown attributes implementing `Validates` are still
     * supported — instantiated **once** at compile time and
     * captured by the closure via `use ($validators)`, so the
     * `newInstance()` cost amortises across every bind() call.
     *
     *   $bind = Binder::compileFor(CreateProduct::class);
     *   $dto  = $bind($bag);
     *   // throws ValidationException with collected errors on failure
     *
     * @template T of RequestDto
     * @param class-string<T> $class
     * @return \Closure(array): T
     */
    public static function compileFor(string $class): \Closure
    {
        if (isset(self::$compiledCache[$class])) {
            /** @var \Closure(array): T */
            return self::$compiledCache[$class];
        }
        $closure = self::buildCompiled($class);
        self::$compiledCache[$class] = $closure;
        /** @var \Closure(array): T */
        return $closure;
    }

    /**
     * @template T of RequestDto
     * @param class-string<T> $class
     * @return \Closure(array): T
     */
    private static function buildCompiled(string $class): \Closure
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->implementsInterface(RequestDto::class)) {
            throw new \InvalidArgumentException("$class must implement " . RequestDto::class);
        }

        // Side-table for non-inlinable validators: pre-instantiated
        // at compile time, dispatched by index from the closure's
        // `use ($validators)`.
        $validators = [];
        $body = "    \$errors = [];\n";
        $body .= "    \$dto = new \\" . ltrim($class, '\\') . "();\n";
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $body .= self::compileProperty($prop, $validators);
        }
        $body .= "    if (\$errors !== []) {\n"
              .  "        throw new \\Rxn\\Framework\\Http\\Binding\\ValidationException(\$errors);\n"
              .  "    }\n"
              .  "    return \$dto;\n";

        $code = "return static function (array \$bag) use (\$validators): \\" . ltrim($class, '\\')
              . " {\n" . $body . "};";

        /** @var \Closure(array): RequestDto $closure */
        $closure = eval($code);
        if (!$closure instanceof \Closure) {
            throw new \RuntimeException("Binder: failed to compile binder for $class");
        }
        return $closure;
    }

    /**
     * Generate the PHP fragment that hydrates and validates one
     * property of the DTO.
     *
     * @param array<int, object> $validators side-table for non-inlinable validators
     */
    private static function compileProperty(\ReflectionProperty $prop, array &$validators): string
    {
        $name   = $prop->getName();
        $nameQ  = self::quoteString($name);
        $type   = $prop->getType();
        $access = "\$dto->" . self::ensureIdentifier($name);

        $isRequired   = $prop->getAttributes(Required::class) !== [];
        $hasDefault   = $prop->hasDefaultValue();
        $allowsNull   = $type instanceof \ReflectionNamedType && $type->allowsNull();

        $missingBranch  = '';
        if ($isRequired) {
            $missingBranch = "        \$errors[] = ['field' => $nameQ, 'message' => 'is required'];\n";
        } elseif ($hasDefault) {
            $defaultLit = var_export($prop->getDefaultValue(), true);
            $missingBranch = "        $access = $defaultLit;\n";
        } elseif ($allowsNull) {
            $missingBranch = "        $access = null;\n";
        }
        // else: leave the property uninitialised (matches the
        // current runtime behaviour for non-required, no-default,
        // non-nullable properties — the property remains in its
        // "no value" state).

        $castExpr = self::castExpression($type);
        $castFail = "        \$errors[] = ['field' => $nameQ, 'message' => 'type mismatch'];\n";

        // Per-property validations from attributes.
        $validateBlock = '';
        foreach ($prop->getAttributes() as $attr) {
            $attrName = $attr->getName();
            if ($attrName === Required::class) {
                continue; // handled at the field level
            }
            $inlined = self::inlineValidator($attrName, $attr->getArguments(), '$cast', $nameQ);
            if ($inlined !== null) {
                $validateBlock .= $inlined;
                continue;
            }
            // Non-inlinable attribute — instantiate once at compile,
            // dispatch via index. Only if it implements Validates;
            // otherwise it's a marker like Required and we ignore it.
            if (is_subclass_of($attrName, Validates::class) || in_array(Validates::class, class_implements($attrName) ?: [], true)) {
                $instance = $attr->newInstance();
                $idx = array_push($validators, $instance) - 1;
                $validateBlock .= "        \$msg = \$validators[$idx]->validate(\$cast);\n"
                               .  "        if (\$msg !== null) { \$errors[] = ['field' => $nameQ, 'message' => \$msg]; }\n";
            }
        }

        $src = "    // -- property: $name\n";
        $src .= "    if (!\\array_key_exists($nameQ, \$bag) || \$bag[$nameQ] === null || \$bag[$nameQ] === '') {\n";
        if ($missingBranch !== '') {
            $src .= $missingBranch;
        }
        $src .= "    } else {\n"
             .  "        \$value = \$bag[$nameQ];\n"
             .  "        $castExpr\n"
             .  "        if (\$cast === null && \$castFailed) {\n"
             .  $castFail
             .  "        } else {\n"
             .  $validateBlock
             .  "            $access = \$cast;\n"
             .  "        }\n"
             .  "    }\n";

        return $src;
    }

    /**
     * Build the cast expression for a property type. Sets `$cast`
     * to the coerced value and `$castFailed` to true on failure.
     * Returns the multi-line PHP fragment to inline.
     */
    private static function castExpression(?\ReflectionType $type): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            // Untyped or union/intersection — accept the value as-is.
            return "\$cast = \$value; \$castFailed = false;";
        }
        $name = $type->getName();
        return match ($name) {
            'mixed'    => "\$cast = \$value; \$castFailed = false;",
            'string'   => "if (\\is_array(\$value)) { \$cast = null; \$castFailed = true; }\n"
                       .  "        elseif (\\is_scalar(\$value)) { \$cast = (string)\$value; \$castFailed = false; }\n"
                       .  "        else { \$cast = null; \$castFailed = true; }",
            'int'      => "if (\\is_array(\$value)) { \$cast = null; \$castFailed = true; }\n"
                       .  "        elseif (\\is_numeric(\$value) && (string)(int)\$value === (string)\$value) { \$cast = (int)\$value; \$castFailed = false; }\n"
                       .  "        else { \$cast = null; \$castFailed = true; }",
            'float'    => "if (\\is_array(\$value)) { \$cast = null; \$castFailed = true; }\n"
                       .  "        elseif (\\is_numeric(\$value)) { \$cast = (float)\$value; \$castFailed = false; }\n"
                       .  "        else { \$cast = null; \$castFailed = true; }",
            'bool'     => "if (\\is_bool(\$value)) { \$cast = \$value; \$castFailed = false; }\n"
                       .  "        elseif (\\is_array(\$value)) { \$cast = null; \$castFailed = true; }\n"
                       .  "        else {\n"
                       .  "            \$lower = \\strtolower((string)\$value);\n"
                       .  "            if (\$lower === '1' || \$lower === 'true' || \$lower === 'yes' || \$lower === 'on') { \$cast = true; \$castFailed = false; }\n"
                       .  "            elseif (\$lower === '0' || \$lower === 'false' || \$lower === 'no' || \$lower === 'off') { \$cast = false; \$castFailed = false; }\n"
                       .  "            else { \$cast = null; \$castFailed = true; }\n"
                       .  "        }",
            'array',
            'iterable' => "if (\\is_array(\$value)) { \$cast = \$value; \$castFailed = false; }\n"
                       .  "        else { \$cast = null; \$castFailed = true; }",
            default    => "\$cast = null; \$castFailed = true;",
        };
    }

    /**
     * Emit inline PHP for one of the framework's known validation
     * attributes. Returns null when the attribute isn't inlinable
     * (caller falls back to the side-table dispatch).
     *
     * @param array<int|string, mixed> $args attribute constructor args
     */
    private static function inlineValidator(string $attrName, array $args, string $valueExpr, string $fieldQ): ?string
    {
        return match ($attrName) {
            Min::class        => self::inlineMin($args, $valueExpr, $fieldQ),
            Max::class        => self::inlineMax($args, $valueExpr, $fieldQ),
            Length::class     => self::inlineLength($args, $valueExpr, $fieldQ),
            Pattern::class    => self::inlinePattern($args, $valueExpr, $fieldQ),
            InSet::class      => self::inlineInSet($args, $valueExpr, $fieldQ),
            Email::class      => self::inlineFilter($valueExpr, $fieldQ, 'FILTER_VALIDATE_EMAIL', 'must be a valid email address'),
            Url::class        => self::inlineFilter($valueExpr, $fieldQ, 'FILTER_VALIDATE_URL', 'must be a valid URL'),
            Uuid::class       => self::inlineUuid($valueExpr, $fieldQ),
            Json::class       => self::inlineJson($valueExpr, $fieldQ),
            Date::class       => self::inlineDate($valueExpr, $fieldQ),
            NotBlank::class   => self::inlineNotBlank($valueExpr, $fieldQ),
            StartsWith::class => self::inlineStartsEnds($args, $valueExpr, $fieldQ, 'starts_with'),
            EndsWith::class   => self::inlineStartsEnds($args, $valueExpr, $fieldQ, 'ends_with'),
            default           => null,
        };
    }

    private static function inlineFilter(string $valueExpr, string $fieldQ, string $filterConst, string $msg): string
    {
        return "        if (\\is_string($valueExpr) && \\filter_var($valueExpr, \\$filterConst) === false) {\n"
             . "            \$errors[] = ['field' => $fieldQ, 'message' => " . self::quoteString($msg) . "];\n"
             . "        }\n";
    }

    private static function inlineUuid(string $valueExpr, string $fieldQ): string
    {
        $regexQ = self::quoteString(Uuid::REGEX);
        return "        if (\\is_string($valueExpr) && \\preg_match($regexQ, $valueExpr) !== 1) {\n"
             . "            \$errors[] = ['field' => $fieldQ, 'message' => 'must be a valid UUID'];\n"
             . "        }\n";
    }

    private static function inlineJson(string $valueExpr, string $fieldQ): string
    {
        return "        if (\\is_string($valueExpr)) {\n"
             . "            if (\\function_exists('json_validate')) {\n"
             . "                if (!\\json_validate($valueExpr)) {\n"
             . "                    \$errors[] = ['field' => $fieldQ, 'message' => 'must be valid JSON'];\n"
             . "                }\n"
             . "            } else {\n"
             . "                try {\n"
             . "                    \\json_decode($valueExpr, true, 512, \\JSON_THROW_ON_ERROR);\n"
             . "                } catch (\\JsonException) {\n"
             . "                    \$errors[] = ['field' => $fieldQ, 'message' => 'must be valid JSON'];\n"
             . "                }\n"
             . "            }\n"
             . "        }\n";
    }

    private static function inlineDate(string $valueExpr, string $fieldQ): string
    {
        return "        if (\\is_string($valueExpr)) {\n"
             . "            if (\\str_contains($valueExpr, \"\0\")) {\n"
             . "                \$errors[] = ['field' => $fieldQ, 'message' => 'must be a valid date (YYYY-MM-DD)'];\n"
             . "            } else {\n"
             . "                \$dt = \\DateTimeImmutable::createFromFormat('!Y-m-d', $valueExpr);\n"             . "                if (\$dt === false || \$dt->format('Y-m-d') !== $valueExpr) {\n"
             . "                    \$errors[] = ['field' => $fieldQ, 'message' => 'must be a valid date (YYYY-MM-DD)'];\n"
             . "                }\n"
             . "            }\n"
             . "        }\n";
    }

    private static function inlineNotBlank(string $valueExpr, string $fieldQ): string
    {
        return "        if (\\is_string($valueExpr) && \\trim($valueExpr) === '') {\n"
             . "            \$errors[] = ['field' => $fieldQ, 'message' => 'must not be blank'];\n"
             . "        }\n";
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private static function inlineStartsEnds(array $args, string $valueExpr, string $fieldQ, string $kind): string
    {
        $argName = $kind === 'starts_with' ? 'prefix' : 'suffix';
        $arg = $args[0] ?? $args[$argName] ?? '';
        $argQ = self::quoteString((string)$arg);
        $fn   = $kind === 'starts_with' ? '\\str_starts_with' : '\\str_ends_with';
        $msg  = $kind === 'starts_with'
            ? "must start with '$arg'"
            : "must end with '$arg'";
        return "        if (\\is_string($valueExpr) && !$fn($valueExpr, $argQ)) {\n"
             . "            \$errors[] = ['field' => $fieldQ, 'message' => " . self::quoteString($msg) . "];\n"
             . "        }\n";
    }

    /** @param array<int|string, mixed> $args */
    private static function inlineMin(array $args, string $valueExpr, string $fieldQ): string
    {
        $min = $args[0] ?? $args['min'] ?? 0;
        $minLit = is_int($min) ? (string)$min : var_export((float)$min, true);
        return "        if ((\\is_int($valueExpr) || \\is_float($valueExpr)) && $valueExpr < $minLit) {\n"
             . "            \$errors[] = ['field' => $fieldQ, 'message' => 'must be >= ' . $minLit];\n"
             . "        }\n";
    }

    /** @param array<int|string, mixed> $args */
    private static function inlineMax(array $args, string $valueExpr, string $fieldQ): string
    {
        $max = $args[0] ?? $args['max'] ?? 0;
        $maxLit = is_int($max) ? (string)$max : var_export((float)$max, true);
        return "        if ((\\is_int($valueExpr) || \\is_float($valueExpr)) && $valueExpr > $maxLit) {\n"
             . "            \$errors[] = ['field' => $fieldQ, 'message' => 'must be <= ' . $maxLit];\n"
             . "        }\n";
    }

    /** @param array<int|string, mixed> $args */
    private static function inlineLength(array $args, string $valueExpr, string $fieldQ): string
    {
        $min = $args[0] ?? $args['min'] ?? null;
        $max = $args[1] ?? $args['max'] ?? null;
        $body = "        if (\\is_string($valueExpr)) {\n"
              . "            \$len = \\mb_strlen($valueExpr);\n";
        if ($min !== null) {
            $body .= "            if (\$len < $min) {\n"
                  .  "                \$errors[] = ['field' => $fieldQ, 'message' => 'must be at least $min characters'];\n"
                  .  "            }\n";
        }
        if ($max !== null) {
            $body .= "            if (\$len > $max) {\n"
                  .  "                \$errors[] = ['field' => $fieldQ, 'message' => 'must be at most $max characters'];\n"
                  .  "            }\n";
        }
        $body .= "        }\n";
        return $body;
    }

    /** @param array<int|string, mixed> $args */
    private static function inlinePattern(array $args, string $valueExpr, string $fieldQ): string
    {
        $regex = $args[0] ?? $args['regex'] ?? '/.*/';
        $regexQ = self::quoteString((string)$regex);
        return "        if (\\is_string($valueExpr) && \\preg_match($regexQ, $valueExpr) !== 1) {\n"
             . "            \$errors[] = ['field' => $fieldQ, 'message' => 'does not match required pattern'];\n"
             . "        }\n";
    }

    /** @param array<int|string, mixed> $args */
    private static function inlineInSet(array $args, string $valueExpr, string $fieldQ): string
    {
        $values = $args[0] ?? $args['values'] ?? [];
        if (!is_array($values)) {
            return "        // InSet inline disabled: values arg is not a literal array\n";
        }
        $literal = var_export(array_values($values), true);
        $allowedDescription = implode(', ', array_map(
            static fn ($v) => is_string($v) ? "'$v'" : (string)$v,
            $values,
        ));
        $allowedQ = self::quoteString("must be one of: $allowedDescription");
        return "        if (!\\in_array($valueExpr, $literal, true)) {\n"
             . "            \$errors[] = ['field' => $fieldQ, 'message' => $allowedQ];\n"
             . "        }\n";
    }

    private static function quoteString(string $s): string
    {
        return "'" . strtr($s, ["\\" => "\\\\", "'" => "\\'"]) . "'";
    }

    private static function ensureIdentifier(string $name): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new \LogicException("Binder: refusing to compile property '$name' — non-identifier name");
        }
        return $name;
    }

    /**
     * Sentinel for `cast()` failures. Can't use null because null
     * is a legal cast result.
     */
    private const CAST_FAIL = "\0__rxn_cast_fail__\0";

    /**
     * Coerce a raw request value to the declared property type.
     * PHP delivers request values as strings (or arrays), so the
     * rules are deliberately minimal: numeric strings → int/float,
     * "true"/"1"/"0"/"false" → bool, arrays stay arrays.
     *
     * @return mixed|string CAST_FAIL on failure
     */
    private static function cast(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }
        $name = $type->getName();

        if ($name === 'mixed') {
            return $value;
        }
        if (is_array($value)) {
            return $name === 'array' || $name === 'iterable' ? $value : self::CAST_FAIL;
        }

        return match ($name) {
            'string'       => is_scalar($value) ? (string)$value : self::CAST_FAIL,
            'int'          => is_numeric($value) && (string)(int)$value === (string)$value
                ? (int)$value : self::CAST_FAIL,
            'float'        => is_numeric($value) ? (float)$value : self::CAST_FAIL,
            'bool'         => self::castBool($value),
            'array',
            'iterable'     => self::CAST_FAIL, // arrays already handled above
            default        => self::CAST_FAIL,
        };
    }

    /** @return bool|string CAST_FAIL on unrecognised input */
    private static function castBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        $lower = strtolower((string)$value);
        return match ($lower) {
            '1', 'true', 'yes', 'on'   => true,
            '0', 'false', 'no', 'off'  => false,
            default                    => self::CAST_FAIL,
        };
    }

    /**
     * Merge GET + POST into a single bag. POST wins on conflicts
     * (the request body is a stronger signal of intent than the
     * query string). Header fields aren't included; if a DTO
     * field needs a header, expose it as a regular controller
     * parameter instead.
     *
     * @return array<string, mixed>
     */
    private static function gatherBag(): array
    {
        return array_merge($_GET ?? [], $_POST ?? []);
    }
}
