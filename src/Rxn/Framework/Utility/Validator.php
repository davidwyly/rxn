<?php declare(strict_types=1);

namespace Rxn\Framework\Utility;

/**
 * Minimal rule-based input validator. No magic, no reflection, no
 * runtime dependencies — just a map of field => rules that gets
 * applied to a payload and returns a list of errors.
 *
 * Each rule is either:
 *   - a keyword string: 'required', 'string', 'int', 'numeric',
 *     'bool', 'array', 'email', 'url', 'uuid', 'ip', 'ipv4',
 *     'ipv6', 'json', 'date', 'datetime', 'not_blank'
 *   - a `name:arg[,arg]*` string: 'min:1', 'max:255', 'between:1,10',
 *     'in:foo,bar,baz', 'regex:/pattern/', 'starts_with:prefix',
 *     'ends_with:suffix'
 *   - a callable `fn($value, $field): ?string` returning an error
 *     message (or null when the value is acceptable). Bare strings
 *     are always interpreted as rule names — even when they happen
 *     to match a PHP builtin function name like `'date'`.
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
     * RFC 4122 UUID — any version, lowercase or uppercase hex.
     * Used by both the `uuid` rule (Validator::evaluate) and the
     * compiled rule emitter (Validator::compileRule).
     */
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<int, string|callable>> $rules
     * @return array<string, string[]> errors keyed by field
     */
    /**
     * Cache of eval-compiled validators keyed by `serialize($rules)`.
     * Same rule set → same closure → reused across calls.
     *
     * @var array<string, \Closure(array): array>
     */
    private static array $compiledCache = [];

    /**
     * Compile a rule set into a single closure that runs the whole
     * check inline — no per-call rule parsing, no per-rule switch
     * dispatch, no per-rule helper invocation. Mirror image of the
     * Fastify schema-compile trick, applied to a path where the
     * baseline is pure PHP (so the user-space generated code can
     * actually beat it).
     *
     *   $check  = Validator::compile([
     *       'email' => ['required', 'email'],
     *       'age'   => ['required', 'int', 'min:18'],
     *   ]);
     *   $errors = $check($payload);  // same shape as check()
     *
     * Use this when the same rule set runs on every request — DTO
     * binding, controller boundary checks, etc. The per-call
     * `check()` form stays for ad-hoc / one-shot validation.
     *
     * Identical rule sets share a closure (cache key is
     * `serialize($rules)`); rules containing closures bypass the
     * cache and recompile, since closures aren't safely
     * serialisable.
     *
     * @param array<string, array<int, string|callable>> $rules
     * @return \Closure(array): array
     */
    public static function compile(array $rules): \Closure
    {
        $cacheable = self::isCacheable($rules);
        if ($cacheable) {
            $key = serialize($rules);
            if (isset(self::$compiledCache[$key])) {
                return self::$compiledCache[$key];
            }
        }
        $closure = self::buildCompiled($rules);
        if ($cacheable) {
            self::$compiledCache[$key] = $closure;
        }
        return $closure;
    }

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
        // Rules are *either* a name string ("email", "min:18", etc.)
        // *or* a real callable (Closure / [obj, 'method'] / invokable
        // object). A bare string is never both — `is_callable("date")`
        // is true because `date` is a PHP builtin, but here it's a
        // rule name. The string-name path takes precedence.
        if (!is_string($rule) && is_callable($rule)) {
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
            case 'uuid':
                return is_string($value) && preg_match(self::UUID_REGEX, $value) === 1
                    ? null
                    : "$field must be a valid UUID";
            case 'ip':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false
                    ? null
                    : "$field must be a valid IP address";
            case 'ipv4':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    ? null
                    : "$field must be a valid IPv4 address";
            case 'ipv6':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    ? null
                    : "$field must be a valid IPv6 address";
            case 'json':
                return self::isValidJson($value)
                    ? null
                    : "$field must be valid JSON";
            case 'date':
                return self::isValidDate($value, 'Y-m-d')
                    ? null
                    : "$field must be a valid date (YYYY-MM-DD)";
            case 'datetime':
                return self::isValidDateTime($value)
                    ? null
                    : "$field must be a valid ISO-8601 datetime";
            case 'not_blank':
                return is_string($value) && trim($value) !== ''
                    ? null
                    : "$field must not be blank";
            case 'starts_with':
                if ($arg === null) {
                    throw new \InvalidArgumentException("starts_with rule requires a prefix");
                }
                return is_string($value) && str_starts_with($value, $arg)
                    ? null
                    : "$field must start with '$arg'";
            case 'ends_with':
                if ($arg === null) {
                    throw new \InvalidArgumentException("ends_with rule requires a suffix");
                }
                return is_string($value) && str_ends_with($value, $arg)
                    ? null
                    : "$field must end with '$arg'";
            default:
                throw new \InvalidArgumentException("Unknown validation rule '$rule'");
        }
    }

    /**
     * @param array<string, array<int, string|callable>> $rules
     */
    private static function isCacheable(array $rules): bool
    {
        foreach ($rules as $fieldRules) {
            foreach ($fieldRules as $r) {
                if (!is_string($r) && is_callable($r)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Generate PHP source for a closure that walks $rules inline.
     * The body is one branch-free fragment per (field, rule) pair —
     * no rule parsing, no switch dispatch, no per-rule helper call.
     *
     * @param array<string, array<int, string|callable>> $rules
     * @return \Closure(array): array
     */
    private static function buildCompiled(array $rules): \Closure
    {
        // Per-compilation table of callable rules. Closures can't go
        // into the eval'd source, so we hold them on the side and
        // dispatch by index from the closure's `use ($callables)`.
        $callables = [];

        $body = "    \$errors = [];\n";
        foreach ($rules as $field => $fieldRules) {
            $body .= self::compileField((string)$field, $fieldRules, $callables);
        }
        $body .= "    return \$errors;\n";

        $code = "return static function (array \$payload) use (\$callables): array {\n"
              . $body
              . "};";

        /** @var \Closure(array): array $closure */
        $closure = eval($code);
        if (!$closure instanceof \Closure) {
            throw new \RuntimeException('Validator: failed to compile rule set');
        }
        return $closure;
    }

    /**
     * @param array<int, string|callable> $fieldRules
     * @param array<int, callable>        $callables  out-param table
     */
    private static function compileField(string $field, array $fieldRules, array &$callables): string
    {
        $fieldQ = self::quoteString($field);
        $required = false;
        $other    = [];
        foreach ($fieldRules as $rule) {
            if ($rule === 'required') {
                $required = true;
                continue;
            }
            $other[] = $rule;
        }

        $src = "    /* field: " . self::quoteInlineComment($field) . " */\n";
        $src .= "    \$value = \$payload[$fieldQ] ?? null;\n";
        $src .= "    \$present = \\array_key_exists($fieldQ, \$payload) && \$value !== '' && \$value !== null;\n";
        $src .= "    if (!\$present) {\n";
        if ($required) {
            $src .= "        \$errors[$fieldQ][] = " . self::quoteString("$field is required") . ";\n";
        }
        $src .= "    } else {\n";
        foreach ($other as $rule) {
            $src .= self::compileRule($field, $rule, $callables);
        }
        $src .= "    }\n";
        return $src;
    }

    /**
     * Emit the PHP fragment that runs a single rule against the
     * already-extracted `$value` of `$field` (in the surrounding
     * else-branch generated by compileField).
     *
     * @param array<int, callable> $callables
     */
    private static function compileRule(string $field, string|callable $rule, array &$callables): string
    {
        $fieldQ = self::quoteString($field);

        if (!is_string($rule) && is_callable($rule)) {
            // Should already be filtered out by isCacheable — but
            // handle it for the no-cache path so identity is the same.
            $idx = array_push($callables, $rule) - 1;
            return "        \$result = \$callables[$idx](\$value, $fieldQ);\n"
                 . "        if (\\is_string(\$result) && \$result !== '') { \$errors[$fieldQ][] = \$result; }\n";
        }

        $rule  = (string) $rule;
        $colon = strpos($rule, ':');
        if ($colon === false) {
            $name = $rule;
            $arg  = null;
        } else {
            $name = substr($rule, 0, $colon);
            $arg  = substr($rule, $colon + 1);
        }

        return match ($name) {
            'string' => "        if (!\\is_string(\$value)) { \$errors[$fieldQ][] = " . self::quoteString("$field must be a string") . "; }\n",
            'int'    => "        if (!(\\is_int(\$value) || (\\is_string(\$value) && \\ctype_digit(\\ltrim(\$value, '-'))))) { \$errors[$fieldQ][] = " . self::quoteString("$field must be an integer") . "; }\n",
            'numeric'=> "        if (!\\is_numeric(\$value)) { \$errors[$fieldQ][] = " . self::quoteString("$field must be numeric") . "; }\n",
            'bool'   => "        if (!(\\is_bool(\$value) || \$value === 0 || \$value === 1 || \$value === '0' || \$value === '1' || \$value === 'true' || \$value === 'false')) { \$errors[$fieldQ][] = " . self::quoteString("$field must be a boolean") . "; }\n",
            'array'  => "        if (!\\is_array(\$value)) { \$errors[$fieldQ][] = " . self::quoteString("$field must be an array") . "; }\n",
            'email'  => "        if (\\filter_var(\$value, \\FILTER_VALIDATE_EMAIL) === false) { \$errors[$fieldQ][] = " . self::quoteString("$field must be a valid email address") . "; }\n",
            'url'    => "        if (\\filter_var(\$value, \\FILTER_VALIDATE_URL) === false) { \$errors[$fieldQ][] = " . self::quoteString("$field must be a valid URL") . "; }\n",
            'min'    => self::compileSizeRule($field, (float)$arg, '<', '>='),
            'max'    => self::compileSizeRule($field, (float)$arg, '>', '<='),
            'between'=> self::compileBetweenRule($field, $arg),
            'in'     => self::compileInRule($field, $arg),
            'regex'  => self::compileRegexRule($field, $arg),
            'uuid'   => "        if (!(\\is_string(\$value) && \\preg_match(" . self::quoteString(self::UUID_REGEX) . ", \$value) === 1)) { \$errors[$fieldQ][] = " . self::quoteString("$field must be a valid UUID") . "; }\n",
            'ip'     => "        if (!(\\is_string(\$value) && \\filter_var(\$value, \\FILTER_VALIDATE_IP) !== false)) { \$errors[$fieldQ][] = " . self::quoteString("$field must be a valid IP address") . "; }\n",
            'ipv4'   => "        if (!(\\is_string(\$value) && \\filter_var(\$value, \\FILTER_VALIDATE_IP, \\FILTER_FLAG_IPV4) !== false)) { \$errors[$fieldQ][] = " . self::quoteString("$field must be a valid IPv4 address") . "; }\n",
            'ipv6'   => "        if (!(\\is_string(\$value) && \\filter_var(\$value, \\FILTER_VALIDATE_IP, \\FILTER_FLAG_IPV6) !== false)) { \$errors[$fieldQ][] = " . self::quoteString("$field must be a valid IPv6 address") . "; }\n",
            'json'   => "        if (!\\Rxn\\Framework\\Utility\\Validator::isValidJson(\$value)) { \$errors[$fieldQ][] = " . self::quoteString("$field must be valid JSON") . "; }\n",
            'date'    => self::compileDateRule($field, 'Y-m-d', "$field must be a valid date (YYYY-MM-DD)"),
            'datetime'=> self::compileDateTimeRule($field),
            'not_blank' => "        if (!(\\is_string(\$value) && \\trim(\$value) !== '')) { \$errors[$fieldQ][] = " . self::quoteString("$field must not be blank") . "; }\n",
            'starts_with' => self::compileStartsEndsRule($field, $arg, 'starts_with'),
            'ends_with'   => self::compileStartsEndsRule($field, $arg, 'ends_with'),
            default  => throw new \InvalidArgumentException("Unknown validation rule '$rule'"),
        };
    }

    private static function compileDateRule(string $field, string $format, string $message): string
    {
        $fieldQ   = self::quoteString($field);
        $formatQ  = self::quoteString($format);
        $msgQ     = self::quoteString($message);
        return "        if (!\\is_string(\$value)) { \$errors[$fieldQ][] = $msgQ; }\n"
             . "        else {\n"
             . "            try {\n"
             . "                \$dt = \\DateTimeImmutable::createFromFormat('!' . $formatQ, \$value);\n"
             . "                if (\$dt === false || \$dt->format($formatQ) !== \$value) { \$errors[$fieldQ][] = $msgQ; }\n"
             . "            } catch (\\ValueError) {\n"
             . "                \$errors[$fieldQ][] = $msgQ;\n"
             . "            }\n"
             . "        }\n";
    }

    private static function compileDateTimeRule(string $field): string
    {
        $fieldQ = self::quoteString($field);
        $msgQ   = self::quoteString("$field must be a valid ISO-8601 datetime");
        // Same format list as Validator::isValidDateTime — kept in
        // sync so runtime and compiled paths accept the same shapes.
        return "        if (!\\is_string(\$value)) { \$errors[$fieldQ][] = $msgQ; }\n"
             . "        else {\n"
             . "            \$ok = false;\n"
             . "            foreach ([\\DateTimeInterface::RFC3339, \\DateTimeInterface::ATOM, 'Y-m-d\\\\TH:i:s\\\\Z', 'Y-m-d\\\\TH:i:sP', 'Y-m-d H:i:s'] as \$f) {\n"
             . "                try {\n"
             . "                    \$dt = \\DateTimeImmutable::createFromFormat(\$f, \$value);\n"
             . "                    if (\$dt !== false && \$dt->format(\$f) === \$value) { \$ok = true; break; }\n"
             . "                } catch (\\ValueError) {\n"
             . "                    \$ok = false;\n"
             . "                    break;\n"
             . "                }\n"
             . "            }\n"
             . "            if (!\$ok) { \$errors[$fieldQ][] = $msgQ; }\n"
             . "        }\n";
    }

    private static function compileStartsEndsRule(string $field, ?string $arg, string $kind): string
    {
        if ($arg === null) {
            throw new \InvalidArgumentException("$kind rule requires an argument");
        }
        $fieldQ = self::quoteString($field);
        $argQ   = self::quoteString($arg);
        $fn     = $kind === 'starts_with' ? '\\str_starts_with' : '\\str_ends_with';
        $msg    = $kind === 'starts_with'
            ? "$field must start with '$arg'"
            : "$field must end with '$arg'";
        return "        if (!(\\is_string(\$value) && $fn(\$value, $argQ))) { \$errors[$fieldQ][] = " . self::quoteString($msg) . "; }\n";
    }

    private static function compileSizeRule(string $field, float $threshold, string $failOp, string $verb): string
    {
        $fieldQ = self::quoteString($field);
        $msg    = self::quoteString("$field must be $verb $threshold");
        $cmp    = $failOp === '<' ? '<' : '>';
        return "        \$size = \\is_string(\$value) ? \\mb_strlen(\$value)\n"
             . "                : (\\is_array(\$value) ? \\count(\$value)\n"
             . "                : (\\is_numeric(\$value) ? (float)\$value : null));\n"
             . "        if (\$size === null) { \$errors[$fieldQ][] = " . self::quoteString("$field has no measurable size") . "; }\n"
             . "        elseif (\$size $cmp $threshold) { \$errors[$fieldQ][] = $msg; }\n";
    }

    private static function compileBetweenRule(string $field, ?string $arg): string
    {
        if ($arg === null || !str_contains($arg, ',')) {
            throw new \InvalidArgumentException("between rule requires 'min,max'");
        }
        [$lo, $hi] = array_map('floatval', explode(',', $arg, 2));
        $fieldQ = self::quoteString($field);
        $msg    = self::quoteString("$field must be between $lo and $hi");
        return "        \$size = \\is_string(\$value) ? \\mb_strlen(\$value)\n"
             . "                : (\\is_array(\$value) ? \\count(\$value)\n"
             . "                : (\\is_numeric(\$value) ? (float)\$value : null));\n"
             . "        if (\$size === null) { \$errors[$fieldQ][] = " . self::quoteString("$field has no measurable size") . "; }\n"
             . "        elseif (\$size < $lo || \$size > $hi) { \$errors[$fieldQ][] = $msg; }\n";
    }

    private static function compileInRule(string $field, ?string $arg): string
    {
        $fieldQ  = self::quoteString($field);
        $allowed = $arg !== null ? explode(',', $arg) : [];
        // Pre-build the allowed array as a PHP literal so the eval'd
        // closure doesn't allocate it each call.
        $literal = '[';
        foreach ($allowed as $a) {
            $literal .= self::quoteString((string)$a) . ',';
        }
        $literal .= ']';
        $msg = self::quoteString("$field must be one of: " . implode(', ', $allowed));
        return "        if (!\\in_array((string)\$value, $literal, true)) { \$errors[$fieldQ][] = $msg; }\n";
    }

    private static function compileRegexRule(string $field, ?string $arg): string
    {
        if ($arg === null) {
            throw new \InvalidArgumentException('regex rule requires a pattern');
        }
        $fieldQ  = self::quoteString($field);
        $patternQ = self::quoteString($arg);
        $msg     = self::quoteString("$field format is invalid");
        return "        if (!(\\is_string(\$value) && \\preg_match($patternQ, \$value) === 1)) { \$errors[$fieldQ][] = $msg; }\n";
    }


    /**
     * Sanitize text embedded in generated one-line comments.
     */
    private static function quoteInlineComment(string $s): string
    {
        return str_replace(["\r", "\n", "*/"], [' ', ' ', '* /'], $s);
    }

    /**
     * Quote a string as a single-quoted PHP literal. Safe for any
     * PHP string — we escape `\` and `'`.
     */
    private static function quoteString(string $s): string
    {
        return "'" . strtr($s, ["\\" => "\\\\", "'" => "\\'"]) . "'";
    }

    /**
     * Strict date check — `$value` must be a string in `$format`
     * and round-trip equal under `DateTimeImmutable::createFromFormat`.
     * Rejects loose `strtotime`-isms ("now", "next tuesday") and
     * out-of-range dates that PHP would otherwise normalise
     * (2024-02-30 → 2024-03-01).
     */
    private static function isValidDate(mixed $value, string $format): bool
    {
        if (!is_string($value)) {
            return false;
        }
        try {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        } catch (\ValueError) {
            return false;
        }
        if ($parsed === false) {
            return false;
        }
        return $parsed->format($format) === $value;
    }

    /**
     * Accepts ISO-8601 / RFC 3339 datetimes — the shape APIs typically
     * exchange. Tries each common format until one round-trips clean.
     */
    private static function isValidDateTime(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        // Common API shapes: 2026-04-29T12:34:56Z,
        // 2026-04-29T12:34:56+00:00, 2026-04-29 12:34:56.
        $formats = [
            \DateTimeInterface::RFC3339,
            \DateTimeInterface::ATOM,
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:sP',
            'Y-m-d H:i:s',
        ];
        foreach ($formats as $format) {
            try {
                $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            } catch (\ValueError) {
                return false;
            }
            if ($parsed !== false && $parsed->format($format) === $value) {
                return true;
            }
        }
        return false;
    }

    public static function isValidJson(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
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
