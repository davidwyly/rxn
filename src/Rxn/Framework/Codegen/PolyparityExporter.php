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
        $isRequired = $prop->getAttributes(Required::class) !== [];
        $allowsNull = $type instanceof \ReflectionNamedType && $type->allowsNull();
        $hasDefault = $prop->hasDefaultValue();
        $default    = $hasDefault ? $prop->getDefaultValue() : null;

        $lines = [];
        $lines[] = '    ' . $name . ':';
        $lines[] = '      type: ' . $this->mapType($type, $name);

        if ($isRequired) {
            $lines[] = '      required: true';
        }
        if ($allowsNull) {
            $lines[] = '      nullable: true';
        }
        if ($hasDefault && $default !== null) {
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
            Length::class   => $this->emitLength($args),
            Min::class      => 'min: ' . $this->yamlScalar($args[0] ?? $args['min'] ?? 0),
            Max::class      => 'max: ' . $this->yamlScalar($args[0] ?? $args['max'] ?? 0),
            InSet::class    => $this->emitInSet($args),
            Url::class      => 'url: true',
            Email::class    => 'email: true',
            default         => $this->refuse($name, $propName),
        };
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
        return null;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function emitLength(array $args): string
    {
        $min = $args[0] ?? $args['min'] ?? null;
        $max = $args[1] ?? $args['max'] ?? null;
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
