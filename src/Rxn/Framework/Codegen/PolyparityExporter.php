<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen;

use Rxn\Framework\Http\Attribute\Email;
use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Max;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\NotBlank;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Attribute\Url;
use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Binding\Validates;

/**
 * Emit a polyparity YAML spec from a Rxn `RequestDto`. The same
 * schema-as-truth principle applies: one PHP class drives Rxn's
 * Binder, the JS twin via `JsValidatorEmitter`, AND a polyparity
 * spec consumable by the polyparity TS / Python / PHP / future
 * implementations.
 *
 *   $yaml = (new PolyparityExporter())->emit(CreateProduct::class);
 *   file_put_contents('create-product.spec.yaml', $yaml);
 *
 * Polyparity (https://github.com/davidwyly/polyparity, private)
 * is a language-neutral spec format with native validators in
 * each target ecosystem. This exporter is the bridge: PHP shops
 * with polyglot frontends can drive both the PHP server and the
 * polyparity-generated client validators from the same DTO.
 *
 * Coverage matrix: identical to `JsValidatorEmitter` —
 * Required, NotBlank, Length, Min, Max, InSet, Email, Url, plus
 * the four scalar types (string, int, float, bool). Same refusal
 * on Pattern / Uuid / Json / Date / StartsWith / EndsWith — those
 * have known cross-runtime divergences in polyparity too.
 */
final class PolyparityExporter
{
    public function emit(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->implementsInterface(RequestDto::class)) {
            throw new \InvalidArgumentException("$class must implement " . RequestDto::class);
        }

        $yaml  = "version: 0.1\n";
        $yaml .= "schema:\n";
        $yaml .= '  name: ' . $reflection->getShortName() . "\n";
        $yaml .= "  fields:\n";

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $yaml .= $this->emitProperty($prop);
        }

        return $yaml;
    }

    private function emitProperty(\ReflectionProperty $prop): string
    {
        $name       = $prop->getName();
        $type       = $prop->getType();
        $allowsNull = $type instanceof \ReflectionNamedType && $type->allowsNull();
        $hasDefault = $prop->hasDefaultValue();
        $default    = $hasDefault ? $prop->getDefaultValue() : null;

        // Required is effectively true in two cases:
        //   1. Explicit #[Required] attribute on the property.
        //   2. Non-nullable, no default — Binder::bind treats this
        //      as required (see the missing-field branch: when the
        //      property has neither a default nor a nullable type,
        //      it adds an "is required" error).
        // Mirror Binder semantics so the exported spec doesn't
        // accept inputs the PHP server rejects.
        $hasRequiredAttr = $prop->getAttributes(Required::class) !== [];
        $isRequired      = $hasRequiredAttr || (!$allowsNull && !$hasDefault);

        $lines = [];
        $lines[] = '    ' . $name . ':';
        $lines[] = '      type: ' . $this->mapType($type, $name);

        if ($isRequired) {
            $lines[] = '      required: true';
        }
        if ($allowsNull) {
            $lines[] = '      nullable: true';
        }
        // Suppress `default:` when the field is required: Binder's
        // missing-field branch fires "is required" before reaching
        // the default-application path, so the default would be a
        // misleading no-op in the spec.
        if (!$isRequired && $hasDefault && $default !== null) {
            $lines[] = '      default: ' . $this->yamlScalar($default);
        }

        $constraints = $this->emitAttributes($prop);
        foreach ($constraints as $line) {
            $lines[] = '      ' . $line;
        }

        return implode("\n", $lines) . "\n\n";
    }

    private function mapType(?\ReflectionType $type, string $propName): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            throw new \RuntimeException(
                "PolyparityExporter: property '$propName' has no scalar type. "
                . 'Polyparity requires string|int|float|bool.',
            );
        }
        return match ($type->getName()) {
            'string' => 'string',
            'int'    => 'int',
            'float'  => 'float',
            'bool'   => 'bool',
            default  => throw new \RuntimeException(
                "PolyparityExporter: property '$propName' has unsupported type '"
                . $type->getName()
                . "'. Polyparity supports string|int|float|bool only.",
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function emitAttributes(\ReflectionProperty $prop): array
    {
        $out = [];
        foreach ($prop->getAttributes() as $attr) {
            $name = $attr->getName();
            if ($name === Required::class) {
                continue;
            }
            $line = $this->emitAttribute($name, $attr->getArguments(), $prop->getName());
            if ($line !== null) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function emitAttribute(string $name, array $args, string $propName): ?string
    {
        return match ($name) {
            NotBlank::class => 'not_blank: true',
            Length::class   => $this->emitLength($args, $propName),
            Min::class      => 'min: ' . $this->yamlScalar($this->requireBound($args, 'min', Min::class, $propName)),
            Max::class      => 'max: ' . $this->yamlScalar($this->requireBound($args, 'max', Max::class, $propName)),
            InSet::class    => $this->emitInSet($args),
            Url::class      => 'url: true',
            Email::class    => 'email: true',
            default         => $this->refuse($name, $propName),
        };
    }

    /**
     * Read the numeric bound from a `#[Min]` / `#[Max]` attribute's
     * raw `getArguments()` payload. Returns the bound as int|float,
     * or throws a clear `RuntimeException` for any input shape Binder
     * would also reject (so the exporter and the runtime stay aligned
     * on what's a "valid" attribute usage).
     *
     * @param array<int|string, mixed> $args
     */
    private function requireBound(array $args, string $key, string $attr, string $propName): int|float
    {
        $value = $args[0] ?? $args[$key] ?? null;
        if ($value === null) {
            // Unreachable in practice: Min/Max attribute constructors
            // require the bound, so PHP throws ArgumentCountError at
            // attribute instantiation. But getArguments() peeks at
            // syntax-tree args without instantiating, so a typo like
            // `#[Min]` (no args) would land here as []. Make the
            // failure explicit instead of silently emitting `min: 0`.
            throw new \RuntimeException(
                "PolyparityExporter: $attr on property '$propName' has no value; "
                . "attribute requires a numeric bound."
            );
        }
        if (!is_int($value) && !is_float($value)) {
            // Min/Max constructors are typed `int|float`. With
            // strict_types in those files, PHP rejects non-numeric
            // inputs (including numeric strings like '5') at
            // newInstance() time. Mirror that here with a clearer
            // exporter-side message rather than letting PHP raise
            // a TypeError on this method's return type.
            $type = get_debug_type($value);
            throw new \RuntimeException(
                "PolyparityExporter: $attr on property '$propName' "
                . "must be int|float, got $type. "
                . "Binder's attribute constructor would reject this too."
            );
        }
        return $value;
    }

    private function refuse(string $attrName, string $propName): ?string
    {
        $known = ['Pattern', 'Uuid', 'Json', 'Date', 'StartsWith', 'EndsWith'];
        $short = (new \ReflectionClass($attrName))->getShortName();
        if (in_array($short, $known, true)) {
            throw new \RuntimeException(
                "PolyparityExporter: $attrName on property '$propName' has no polyparity twin yet. "
                . 'Refusing to emit silently-divergent spec. '
                . 'Add a mapping or document the DTO as PHP-only.',
            );
        }

        if (is_subclass_of($attrName, Validates::class)) {
            throw new \RuntimeException(
                "PolyparityExporter: $attrName on property '$propName' is a custom validator. "
                . 'Refusing to emit silently-divergent spec. '
                . 'Add a polyparity mapping or keep this DTO PHP-only.'
            );
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function emitLength(array $args, string $propName): ?string
    {
        $min = $args[0] ?? $args['min'] ?? null;
        $max = $args[1] ?? $args['max'] ?? null;
        if ($min === null && $max === null) {
            // `#[Length]` with no bounds is a Binder no-op (the
            // validator returns null for both bound checks). Emitting
            // `length: { }` is at best vacuous, at worst invalid for
            // strict polyparity parsers — skip the constraint entirely
            // to mirror the no-op behaviour.
            return null;
        }
        $parts = [];
        if ($min !== null) {
            $parts[] = 'min: ' . (int) $min;
        }
        if ($max !== null) {
            $parts[] = 'max: ' . (int) $max;
        }
        return 'length: { ' . implode(', ', $parts) . ' }';
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function emitInSet(array $args): string
    {
        $values = $args[0] ?? $args['values'] ?? [];
        if (!is_array($values)) {
            throw new \InvalidArgumentException('InSet expects an array of values');
        }
        $rendered = array_map(fn ($v): string => $this->yamlScalar($v), $values);
        return 'one_of: [' . implode(', ', $rendered) . ']';
    }

    private function yamlScalar(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            $s = (string) $v;
            return str_contains($s, '.') || str_contains($s, 'e') || str_contains($s, 'E')
                ? $s
                : $s . '.0';
        }
        if (is_string($v)) {
            return $this->yamlString($v);
        }
        throw new \InvalidArgumentException('Unsupported YAML scalar type: ' . get_debug_type($v));
    }

    private function yamlString(string $s): string
    {
        if ($s === '') {
            return "''";
        }
        if ($this->needsQuoting($s)) {
            $escaped = str_replace("'", "''", $s);
            return "'" . $escaped . "'";
        }
        return $s;
    }

    private function needsQuoting(string $s): bool
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $s) !== 1) {
            return true;
        }
        $reserved = ['true', 'false', 'null', 'yes', 'no', 'on', 'off', '~'];
        if (in_array(strtolower($s), $reserved, true)) {
            return true;
        }
        return false;
    }
}
