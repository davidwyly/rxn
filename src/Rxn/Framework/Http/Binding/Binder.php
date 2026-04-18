<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Binding;

use Rxn\Framework\Http\Attribute\Required;

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
