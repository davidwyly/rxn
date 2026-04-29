<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

/**
 * Schema-compiled JSON encoder for DTOs / value objects with typed
 * public properties.
 *
 * Borrows directly from Fastify's `fast-json-stringify` trick: at
 * registration time, walk the class's public properties via
 * Reflection and **generate PHP source code** for a flat, branch-free
 * string concatenation — one snippet per property — with the
 * appropriate per-type encoding baked in. The generated source is
 * compiled once via `eval()` into a closure that's cached per class
 * for the process lifetime.
 *
 *   final class Product {
 *       public int    $id;
 *       public string $name;
 *       public float  $price;
 *       public ?bool  $active;
 *   }
 *
 *   $encode = CompiledJson::for(Product::class);
 *   echo $encode($product);
 *   // '{"id":42,"name":"Widget","price":9.99,"active":true}'
 *
 * The generated body for `Product` is literally:
 *
 *   return '{"id":'   . (string)$o->id
 *        . ',"name":' . \json_encode($o->name, \JSON_UNESCAPED_SLASHES)
 *        . ',"price":' . \json_encode($o->price)
 *        . ',"active":' . ($o->active === null ? 'null'
 *                           : ($o->active ? 'true' : 'false'))
 *        . '}';
 *
 * No type-detection, no escape-decision, no property iteration —
 * just a flat sequence of typed accesses the JIT can fold tightly.
 *
 * # What's encoded
 *
 * - `int` / `bool` / `string` / `float` / `array` / nullable
 *   variants of each → inlined per-type.
 * - object class properties → fall back to `json_encode($value)`
 *   (callers can compose nested compiled encoders manually).
 * - union / intersection / untyped → fall back to `json_encode`.
 *
 * Only public, non-static properties are emitted (matching DTO
 * conventions). Eval is invoked exactly once per class, on the
 * first `for($class)` call; the eval'd source is built only from
 * reflected names and types, so there's no untrusted input on the
 * code-gen path.
 */
final class CompiledJson
{
    /** @var array<class-string, \Closure(object): string> */
    private static array $cache = [];

    /**
     * Compile (or fetch) the encoder for $class.
     *
     * @param class-string $class
     * @return \Closure(object): string
     */
    public static function for(string $class): \Closure
    {
        return self::$cache[$class] ??= self::compile($class);
    }

    /**
     * Convenience: compile + apply in one shot.
     */
    public static function encode(object $value): string
    {
        return (self::for($value::class))($value);
    }

    /** @var array<class-string, \Closure(iterable): string> */
    private static array $listCache = [];

    /**
     * Compile a batch encoder for `$class` that emits a JSON array
     * (`[obj, obj, ...]`) given an iterable of `$class` instances.
     *
     * Why a separate batch encoder: encoding a list via per-instance
     * `for($class)` calls ~100 PHP closures into existence per
     * request. The batch closure does the foreach internally, so
     * the whole list collapses into a single closure invocation —
     * 100× less PHP-level overhead for the same output. Same
     * inlined per-property encoding as the singular encoder.
     *
     * @param class-string $class
     * @return \Closure(iterable): string
     */
    public static function forList(string $class): \Closure
    {
        return self::$listCache[$class] ??= self::compileList($class);
    }

    /**
     * @param class-string $class
     * @return \Closure(iterable): string
     */
    private static function compileList(string $class): \Closure
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("CompiledJson: $class does not exist");
        }

        $reflection = new \ReflectionClass($class);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        $snippets   = [];
        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $snippets[] = self::snippetFor($property);
        }
        if ($snippets === []) {
            return static function (iterable $list): string {
                $count = is_countable($list) ? count($list) : iterator_count($list);
                return '[' . implode(',', array_fill(0, $count, '{}')) . ']';
            };
        }

        // Build the per-instance fragment from the snippets, with
        // `$o->...` accessing each instance.
        $instanceFragment = "'{'";
        foreach ($snippets as $i => $snippet) {
            $sep = $i === 0 ? '' : ',';
            $instanceFragment .= " . " . self::quote($sep . $snippet['key'] . ':') . " . " . $snippet['value'];
        }
        $instanceFragment .= " . '}'";

        // Loop body: append separator on every iteration after the
        // first. Tracking with a `$first` flag keeps the inner loop
        // branch-free except for one bool check.
        $code = "return static function (iterable \$list): string {\n"
              . "    \$out   = '[';\n"
              . "    \$first = true;\n"
              . "    foreach (\$list as \$o) {\n"
              . "        if (!\$first) { \$out .= ','; }\n"
              . "        \$first = false;\n"
              . "        \$out .= $instanceFragment;\n"
              . "    }\n"
              . "    return \$out . ']';\n"
              . "};";

        /** @var \Closure(iterable): string $closure */
        $closure = eval($code);
        if (!$closure instanceof \Closure) {
            throw new \RuntimeException("CompiledJson: failed to compile list encoder for $class");
        }
        return $closure;
    }

    /**
     * @param class-string $class
     * @return \Closure(object): string
     */
    private static function compile(string $class): \Closure
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("CompiledJson: $class does not exist");
        }

        $reflection = new \ReflectionClass($class);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        // Collect snippets per property. Each snippet is a fragment
        // of the closure body that emits one `,"key":value` chunk.
        $snippets = [];
        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $snippets[] = self::snippetFor($property);
        }

        if ($snippets === []) {
            return static fn (object $o): string => '{}';
        }

        // Stitch the snippets into a single concat expression. The
        // first snippet opens with `{`, the rest with `,`.
        $body = "return '{'";
        foreach ($snippets as $i => $snippet) {
            $sep   = $i === 0 ? '' : ',';
            $body .= " . " . self::quote($sep . $snippet['key'] . ':') . " . " . $snippet['value'];
        }
        $body .= " . '}';";

        // Build the generated closure. Single eval per class.
        $code = "return static function (object \$o): string { $body };";
        /** @var \Closure(object): string $closure */
        $closure = eval($code);
        if (!$closure instanceof \Closure) {
            throw new \RuntimeException("CompiledJson: failed to compile encoder for $class");
        }
        return $closure;
    }

    /**
     * Build the per-property code-gen snippet. Returns the JSON key
     * (as a quoted PHP string fragment) and the PHP expression that
     * encodes the value.
     *
     * @return array{key: string, value: string}
     */
    private static function snippetFor(\ReflectionProperty $property): array
    {
        $name = $property->getName();
        $key  = json_encode($name, JSON_UNESCAPED_SLASHES) ?: '""';
        $type = $property->getType();
        $access = "\$o->" . self::sanitizeName($name);

        $valueExpr = self::valueExpression($access, $type);

        return ['key' => $key, 'value' => $valueExpr];
    }

    /**
     * Generate the PHP expression that encodes one value given its
     * declared type. The expression is inlined into the closure body
     * verbatim, so it must be valid PHP and side-effect-free.
     */
    private static function valueExpression(string $access, ?\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
            $name      = $type->getName();
            $allowsNull = $type->allowsNull();
            $core = match ($name) {
                'int'    => "(string)$access",
                'bool'   => "($access ? 'true' : 'false')",
                'string' => "\\json_encode($access, \\JSON_UNESCAPED_SLASHES)",
                'float'  => "\\json_encode($access)",
                'array'  => "(\\json_encode($access, \\JSON_UNESCAPED_SLASHES) ?: '[]')",
                default  => "(\\json_encode($access, \\JSON_UNESCAPED_SLASHES) ?: 'null')",
            };
            return $allowsNull
                ? "($access === null ? 'null' : $core)"
                : $core;
        }
        // Object class / union / intersection / untyped → full
        // encode. Wrapped with `?:` so a `false` return from
        // json_encode (e.g. on encoding error) emits a JSON null
        // instead of the empty string.
        return "(\\json_encode($access, \\JSON_UNESCAPED_SLASHES) ?: 'null')";
    }

    /**
     * Quote a string as a PHP single-quoted literal for safe
     * interpolation into the generated source.
     */
    private static function quote(string $literal): string
    {
        return "'" . strtr($literal, ["\\" => "\\\\", "'" => "\\'"]) . "'";
    }

    /**
     * Property names are valid PHP identifiers (`[a-zA-Z_][\w]*`),
     * so verbatim interpolation as `$o->name` is safe. This guard
     * makes that invariant explicit so a future refactor doesn't
     * accidentally widen what gets fed into eval.
     */
    private static function sanitizeName(string $name): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new \LogicException("CompiledJson: refusing to compile property '$name' — non-identifier name");
        }
        return $name;
    }
}
