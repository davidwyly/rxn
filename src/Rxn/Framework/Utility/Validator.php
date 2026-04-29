<?php declare(strict_types=1);

namespace Rxn\Framework\Utility;

/**
 * Minimal rule-based input validator. No magic, no reflection, no
 * runtime dependencies — just a map of field => rules that gets
 * applied to a payload and returns a list of errors.
 *
 * Each rule is either:
 *   - a keyword string: 'required', 'string', 'int', 'numeric',
 *     'bool', 'email', 'url', 'array'
 *   - a `name:arg[,arg]*` string: 'min:1', 'max:255', 'between:1,10',
 *     'in:foo,bar,baz', 'regex:/pattern/'
 *   - a callable `fn($value, $field): ?string` returning an error
 *     message (or null when the value is acceptable)
 *
 *   $errors = Validator::check(
 *       ['email' => 'u@example.com', 'age' => 17],
 *       [
 *           'email' => ['required', 'email'],
 *           'age'   => ['required', 'int', 'min:18'],
 *           'role'  => ['in:admin,member,guest'],
 *       ]
 *   );
 *   // $errors === ['age' => ['age must be >= 18']]
 *
 * `assert()` is the convenience form: runs `check()` and throws
 * \InvalidArgumentException with a compact message when any rule
 * fails. Use it from controllers as the boundary check.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<int, string|callable>> $rules
     * @return array<string, string[]> errors keyed by field
     */
    public static function check(array $payload, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $fieldRules) {
            $value    = $payload[$field] ?? null;
            $present  = array_key_exists($field, $payload) && $value !== '' && $value !== null;
            $required = in_array('required', $fieldRules, true);

            if (!$present) {
                if ($required) {
                    $errors[$field][] = "$field is required";
                }
                continue;
            }

            foreach ($fieldRules as $rule) {
                if ($rule === 'required') {
                    continue;
                }
                $error = self::evaluate($field, $value, $rule);
                if ($error !== null) {
                    $errors[$field][] = $error;
                }
            }
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<int, string|callable>> $rules
     * @throws \InvalidArgumentException
     */
    public static function assert(array $payload, array $rules): void
    {
        $errors = self::check($payload, $rules);
        if ($errors === []) {
            return;
        }
        $messages = [];
        foreach ($errors as $list) {
            foreach ($list as $msg) {
                $messages[] = $msg;
            }
        }
        throw new \InvalidArgumentException(implode('; ', $messages));
    }

    /**
     * @param mixed $value
     * @param string|callable $rule
     */
    private static function evaluate(string $field, $value, $rule): ?string
    {
        if (is_callable($rule)) {
            $result = $rule($value, $field);
            return is_string($result) && $result !== '' ? $result : null;
        }

        // Hot path: parse "name:arg" without allocating an array.
        // explode + array_pad allocates a 2-element array per rule;
        // a typical check() call walks 15-20 rules. strpos + substr
        // does the same work with no allocation.
        $rule  = (string) $rule;
        $colon = strpos($rule, ':');
        if ($colon === false) {
            $name = $rule;
            $arg  = null;
        } else {
            $name = substr($rule, 0, $colon);
            $arg  = substr($rule, $colon + 1);
        }

        switch ($name) {
            case 'string':
                return is_string($value) ? null : "$field must be a string";
            case 'int':
                return (is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '-'))))
                    ? null
                    : "$field must be an integer";
            case 'numeric':
                return is_numeric($value) ? null : "$field must be numeric";
            case 'bool':
                return (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1'
                    || $value === 'true' || $value === 'false')
                    ? null
                    : "$field must be a boolean";
            case 'array':
                return is_array($value) ? null : "$field must be an array";
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                    ? null
                    : "$field must be a valid email address";
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false
                    ? null
                    : "$field must be a valid URL";
            case 'min':
                return self::compareSize($field, $value, (float)$arg, '<', '>=');
            case 'max':
                return self::compareSize($field, $value, (float)$arg, '>', '<=');
            case 'between':
                if ($arg === null || !str_contains($arg, ',')) {
                    throw new \InvalidArgumentException("between rule requires 'min,max'");
                }
                [$lo, $hi] = array_map('floatval', explode(',', $arg, 2));
                $low  = self::compareSize($field, $value, $lo, '<', '>=');
                if ($low !== null) {
                    return "$field must be between $lo and $hi";
                }
                $high = self::compareSize($field, $value, $hi, '>', '<=');
                return $high !== null ? "$field must be between $lo and $hi" : null;
            case 'in':
                $allowed = $arg !== null ? explode(',', $arg) : [];
                return in_array((string)$value, $allowed, true)
                    ? null
                    : "$field must be one of: " . implode(', ', $allowed);
            case 'regex':
                if ($arg === null) {
                    throw new \InvalidArgumentException('regex rule requires a pattern');
                }
                return is_string($value) && preg_match($arg, $value) === 1
                    ? null
                    : "$field format is invalid";
            default:
                throw new \InvalidArgumentException("Unknown validation rule '$rule'");
        }
    }

    /**
     * @param mixed $value
     */
    private static function compareSize(string $field, $value, float $threshold, string $failOp, string $verb): ?string
    {
        $size = is_string($value) ? mb_strlen($value)
              : (is_array($value) ? count($value)
              : (is_numeric($value) ? (float)$value : null));
        if ($size === null) {
            return "$field has no measurable size";
        }
        $fails = ($failOp === '<') ? ($size < $threshold) : ($size > $threshold);
        return $fails ? "$field must be $verb $threshold" : null;
    }
}
