<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen\Testing;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Email;
use Rxn\Framework\Http\Attribute\Url;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * DTO-driven random input generator. Reflects the DTO's properties
 * + their declared types + their attributes, then emits a random
 * input bag that intentionally exercises the (type × constraint)
 * matrix — including the boundaries and the type-mismatch corners.
 *
 * Used by `ParityHarness` to drive cross-runtime parity tests
 * for plugins. The bag generator is per-property: each field
 * either is omitted (testing Required), set to null, set to a
 * type-correct value at a constraint boundary, set to a wrong
 * type (string for int, etc.), or set to a known-malformed
 * fixture for the validator (bad URL, blank string, etc.).
 *
 *   $gen   = new AdversarialInputGenerator();
 *   $bag   = $gen->generate(MyDto::class);
 *
 * Same DTO twice produces two different bags — by design. The
 * harness runs N of these; over enough iterations every code
 * path in the validator matrix gets hit.
 */
final class AdversarialInputGenerator
{
    /**
     * @param class-string<RequestDto> $dtoClass
     * @return array<string, mixed>
     */
    public function generate(string $dtoClass): array
    {
        $reflection = new \ReflectionClass($dtoClass);
        $bag = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            // 20% chance: omit the field entirely (exercises Required).
            if (mt_rand(0, 100) < 20) {
                continue;
            }
            $bag[$prop->getName()] = $this->valueForProperty($prop);
        }
        return $bag;
    }

    private function valueForProperty(\ReflectionProperty $prop): mixed
    {
        // 8% chance: explicit null. (PHP runtime treats both
        // missing and null + empty-string identically in the
        // Required check, so this is its own path.)
        if (mt_rand(0, 100) < 8) {
            return null;
        }
        // 4% chance: empty string — same Required-trigger path.
        if (mt_rand(0, 100) < 4) {
            return '';
        }

        // Look for InSet first — its values dominate the field's
        // domain when present; we want to mostly emit values from
        // the allow-list with occasional drift.
        $inSetAttr = $prop->getAttributes(InSet::class)[0] ?? null;
        if ($inSetAttr !== null) {
            $args   = $inSetAttr->getArguments();
            $values = $args[0] ?? $args['values'] ?? [];
            return $this->oneOf([
                ...is_array($values) ? $values : [],
                'NotInSet',
                strtoupper((string) ($values[0] ?? 'X')),  // case mismatch
                '',
            ]);
        }

        // Email or URL: emit half-valid, half-malformed.
        if ($prop->getAttributes(Email::class) !== []) {
            return $this->randomEmailish();
        }
        if ($prop->getAttributes(Url::class) !== []) {
            return $this->randomUrlish();
        }

        // Fall through: type-driven random.
        $type = $prop->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
        return match ($name) {
            'string' => $this->randomStringy(),
            'int'    => $this->randomNumeric(intOnly: true),
            'float'  => $this->randomNumeric(intOnly: false),
            'bool'   => $this->randomBoolish(),
            default  => $this->randomStringy(),
        };
    }

    private function randomStringy(): mixed
    {
        return $this->oneOf([
            '',                                              // empty (Required-equiv)
            ' ',                                             // blank
            'A',                                             // length 1
            str_repeat('x', 50),                             // mid
            str_repeat('y', 100),                            // boundary
            str_repeat('z', 101),                            // over
            'normal text value',
            'with special chars !@#$%',
            123,                                             // numeric → string coerce
            true,                                            // bool → string coerce
            ['nested' => 'object'],                          // array → type mismatch
        ]);
    }

    private function randomNumeric(bool $intOnly): mixed
    {
        $values = [
            0, 1, -1, 9999, -50, 1_000_001,
            '0', '1', '-1', '99', '-50',
            'abc',                                           // not numeric
            '',                                              // empty
            true,                                            // bool
            ['nested'],                                      // array
        ];
        if (!$intOnly) {
            // float adds these — PHP int-cast guard rejects them.
            $values = [
                ...$values,
                0.0, 1.5, -1.5, 99.99, -0.001,
                '1.5', '-50.25', '1e3', '1.5e-2',
            ];
        }
        return $this->oneOf($values);
    }

    private function randomUrlish(): mixed
    {
        return $this->oneOf([
            'https://example.com',
            'http://example.com/path',
            'https://sub.example.com/path?q=1',
            'ftp://example.com',
            'example.com',                                   // no scheme
            'not-a-url',
            '',
            123,
        ]);
    }

    private function randomEmailish(): mixed
    {
        return $this->oneOf([
            'a@b.co',
            'user@example.com',
            'user+tag@example.com',
            'not-an-email',
            'a@b',                                           // no TLD
            '@example.com',                                  // no local part
            '',
        ]);
    }

    private function randomBoolish(): mixed
    {
        return $this->oneOf([
            true, false, 'true', 'false', '1', '0', 'yes', 'no', 'on', 'off',
            'maybe',                                         // invalid
            '', 0, 1,
        ]);
    }

    private function oneOf(array $values): mixed
    {
        return $values[array_rand($values)];
    }
}
