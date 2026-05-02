<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Utility;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Utility\Validator;

final class ValidatorTest extends TestCase
{
    public function testPassingPayloadReturnsNoErrors(): void
    {
        $errors = Validator::check(
            ['email' => 'u@example.com', 'age' => 42],
            [
                'email' => ['required', 'email'],
                'age'   => ['required', 'int', 'min:18'],
            ]
        );
        $this->assertSame([], $errors);
    }

    public function testMissingRequiredFieldIsReported(): void
    {
        $errors = Validator::check([], ['email' => ['required']]);
        $this->assertSame(['email' => ['email is required']], $errors);
    }

    public function testEmptyStringCountsAsMissingForRequired(): void
    {
        $errors = Validator::check(['email' => ''], ['email' => ['required']]);
        $this->assertSame(['email' => ['email is required']], $errors);
    }

    public function testOptionalFieldSkippedWhenAbsent(): void
    {
        $errors = Validator::check([], ['email' => ['email']]);
        $this->assertSame([], $errors);
    }

    public function testEmailRule(): void
    {
        $errors = Validator::check(['email' => 'not an email'], ['email' => ['email']]);
        $this->assertArrayHasKey('email', $errors);
    }

    public function testIntRuleAcceptsNumericStrings(): void
    {
        $this->assertSame([], Validator::check(['age' => '42'], ['age' => ['int']]));
        $this->assertSame([], Validator::check(['age' => 42], ['age' => ['int']]));
        $errors = Validator::check(['age' => '4.5'], ['age' => ['int']]);
        $this->assertArrayHasKey('age', $errors);
    }

    public function testMinMaxOnNumericValue(): void
    {
        $tooLow = Validator::check(['age' => 17], ['age' => ['int', 'min:18']]);
        $this->assertArrayHasKey('age', $tooLow);

        $tooHigh = Validator::check(['age' => 150], ['age' => ['int', 'max:120']]);
        $this->assertArrayHasKey('age', $tooHigh);

        $this->assertSame([], Validator::check(['age' => 30], ['age' => ['int', 'min:18', 'max:120']]));
    }

    public function testMinMaxOnStringLength(): void
    {
        $tooShort = Validator::check(['name' => 'ab'], ['name' => ['string', 'min:3']]);
        $this->assertArrayHasKey('name', $tooShort);

        $this->assertSame([], Validator::check(['name' => 'abc'], ['name' => ['string', 'min:3', 'max:10']]));
    }

    public function testBetweenRule(): void
    {
        $this->assertSame([], Validator::check(['n' => 5], ['n' => ['int', 'between:1,10']]));
        $errors = Validator::check(['n' => 11], ['n' => ['int', 'between:1,10']]);
        $this->assertSame(['n' => ['n must be between 1 and 10']], $errors);
    }

    public function testInRule(): void
    {
        $this->assertSame([], Validator::check(['role' => 'admin'], ['role' => ['in:admin,member,guest']]));
        $errors = Validator::check(['role' => 'owner'], ['role' => ['in:admin,member,guest']]);
        $this->assertArrayHasKey('role', $errors);
    }

    public function testRegexRule(): void
    {
        $this->assertSame([], Validator::check(['slug' => 'abc-123'], ['slug' => ['regex:/^[a-z0-9-]+$/']]));
        $errors = Validator::check(['slug' => 'Bad Slug!'], ['slug' => ['regex:/^[a-z0-9-]+$/']]);
        $this->assertArrayHasKey('slug', $errors);
    }

    public function testCallableRuleReceivesValueAndField(): void
    {
        $seen = [];
        $errors = Validator::check(
            ['x' => 'hi'],
            ['x' => [function ($value, $field) use (&$seen) {
                $seen[] = [$value, $field];
                return $value === 'hi' ? 'x cannot be hi' : null;
            }]]
        );
        $this->assertSame([['hi', 'x']], $seen);
        $this->assertSame(['x' => ['x cannot be hi']], $errors);
    }

    public function testAssertThrowsOnFailure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::assert(['age' => 17], ['age' => ['int', 'min:18']]);
    }

    public function testAssertPassesCleanPayload(): void
    {
        Validator::assert(['age' => 30], ['age' => ['int', 'min:18']]);
        $this->addToAssertionCount(1);
    }

    public function testUnknownRuleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::check(['x' => 1], ['x' => ['definitely_not_a_rule']]);
    }

    public function testMultipleFieldErrorsAreCollected(): void
    {
        $errors = Validator::check(
            ['email' => 'nope', 'age' => 12],
            [
                'email' => ['required', 'email'],
                'age'   => ['required', 'int', 'min:18'],
            ]
        );
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, array<int, string>>}>
     */
    public static function compileParityCases(): iterable
    {
        $rules = [
            'email' => ['required', 'email'],
            'age'   => ['required', 'int', 'min:18', 'max:120'],
            'role'  => ['in:admin,member,guest'],
            'slug'  => ['regex:/^[a-z0-9-]+$/'],
            'tags'  => ['array', 'between:1,5'],
            'title' => ['string', 'min:3', 'max:50'],
            'site'  => ['url'],
            'bio'   => ['string'],
            'price' => ['numeric'],
            'flag'  => ['bool'],
        ];

        yield 'all valid' => [
            [
                'email' => 'u@example.com',
                'age'   => 42,
                'role'  => 'member',
                'slug'  => 'abc-123',
                'tags'  => ['a', 'b'],
                'title' => 'Hello world',
                'site'  => 'https://example.com',
                'bio'   => 'about me',
                'price' => '9.99',
                'flag'  => 'true',
            ],
            $rules,
        ];

        yield 'all missing optional' => [
            ['email' => 'u@example.com', 'age' => 25],
            $rules,
        ];

        yield 'all invalid' => [
            [
                'email' => 'nope',
                'age'   => 12,
                'role'  => 'stranger',
                'slug'  => 'BAD slug',
                'tags'  => 'not-array',
                'title' => 'no',
                'site'  => 'not a url',
                'bio'   => 123,
                'price' => 'free',
                'flag'  => 'maybe',
            ],
            $rules,
        ];

        yield 'edge: empty string == missing' => [
            ['email' => '', 'age' => 30],
            $rules,
        ];
    }

    /** @dataProvider compileParityCases */
    public function testCompiledOutputMatchesCheckOutput(array $payload, array $rules): void
    {
        $expected = Validator::check($payload, $rules);
        $compiled = Validator::compile($rules);
        $this->assertSame($expected, $compiled($payload));
    }

    public function testCompileCachesEquivalentRuleSets(): void
    {
        $rules1 = [
            'email' => ['required', 'email'],
            'age'   => ['int', 'min:18'],
        ];
        $rules2 = [
            'email' => ['required', 'email'],
            'age'   => ['int', 'min:18'],
        ];
        $a = Validator::compile($rules1);
        $b = Validator::compile($rules2);
        $this->assertSame($a, $b);
    }

    public function testCompileBypassesCacheForCallableRules(): void
    {
        $rules = [
            'name' => [
                'required',
                fn ($v, $f) => str_starts_with((string)$v, 'X') ? "$f must not start with X" : null,
            ],
        ];
        $check = Validator::compile($rules);
        $this->assertSame([], $check(['name' => 'Alice']));
        $errors = $check(['name' => 'Xavier']);
        $this->assertSame(['name' => ['name must not start with X']], $errors);
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed, 2: bool}>
     */
    public static function expandedRuleCases(): iterable
    {
        yield 'uuid valid'        => ['uuid', '550e8400-e29b-41d4-a716-446655440000', true];
        yield 'uuid invalid'      => ['uuid', 'not-a-uuid', false];
        yield 'uuid uppercase'    => ['uuid', '550E8400-E29B-41D4-A716-446655440000', true];
        yield 'ip v4 valid'       => ['ip', '192.168.1.1', true];
        yield 'ip v6 valid'       => ['ip', '::1', true];
        yield 'ip invalid'        => ['ip', '999.999.999.999', false];
        yield 'ipv4 v4'           => ['ipv4', '192.168.1.1', true];
        yield 'ipv4 v6 fails'     => ['ipv4', '::1', false];
        yield 'ipv6 v6'           => ['ipv6', '::1', true];
        yield 'ipv6 v4 fails'     => ['ipv6', '192.168.1.1', false];
        yield 'json object'       => ['json', '{"a":1}', true];
        yield 'json array'        => ['json', '[1,2,3]', true];
        yield 'json invalid'      => ['json', 'not json', false];
        yield 'json with null byte escape' => ['json', "\"\\u0000\"", true];
        yield 'date valid'        => ['date', '2026-04-29', true];
        yield 'date bad month'    => ['date', '2026-13-01', false];
        yield 'date phantom day'  => ['date', '2024-02-30', false];
        yield 'date embedded null byte' => ['date', "2026-04-29\0", false];
        yield 'date wrong format' => ['date', '04/29/2026', false];
        yield 'datetime ISO Z'    => ['datetime', '2026-04-29T12:34:56Z', true];
        yield 'datetime ISO offset' => ['datetime', '2026-04-29T12:34:56+00:00', true];
        yield 'datetime SQL'      => ['datetime', '2026-04-29 12:34:56', true];
        yield 'datetime invalid'  => ['datetime', 'tomorrow', false];
        yield 'datetime embedded null byte' => ['datetime', "2026-04-29T12:34:56Z\0", false];
        yield 'not_blank space-only' => ['not_blank', '   ', false];
        yield 'not_blank tab-only'   => ['not_blank', "\t\n", false];
        yield 'not_blank ok'      => ['not_blank', 'hi', true];
        yield 'starts_with hit'   => ['starts_with:abc', 'abcdef', true];
        yield 'starts_with miss'  => ['starts_with:abc', 'xyzdef', false];
        yield 'ends_with hit'     => ['ends_with:xyz', 'abcxyz', true];
        yield 'ends_with miss'    => ['ends_with:xyz', 'abcdef', false];
    }

    /** @dataProvider expandedRuleCases */
    public function testExpandedRuleRuntime(string $rule, mixed $value, bool $shouldPass): void
    {
        $errors = Validator::check(['v' => $value], ['v' => [$rule]]);
        if ($shouldPass) {
            $this->assertSame([], $errors);
        } else {
            $this->assertArrayHasKey('v', $errors);
        }
    }

    /** @dataProvider expandedRuleCases */
    public function testExpandedRuleCompiled(string $rule, mixed $value, bool $shouldPass): void
    {
        $check  = Validator::compile(['v' => [$rule]]);
        $errors = $check(['v' => $value]);
        if ($shouldPass) {
            $this->assertSame([], $errors);
        } else {
            $this->assertArrayHasKey('v', $errors);
        }
    }

    public function testRuleNamedLikePhpFunctionStillTreatedAsString(): void
    {
        // 'date' is also a PHP builtin; is_callable('date') is true.
        // The Validator must treat it as a rule name, not call it as
        // a function. Pre-fix, this threw a TypeError from date()
        // being called with the value as the timestamp arg.
        $errors = Validator::check(['v' => '2026-04-29'], ['v' => ['date']]);
        $this->assertSame([], $errors);
    }
    public function testCompileDoesNotExecuteInjectedFieldNameCode(): void
    {
        $tmp = sys_get_temp_dir() . '/rxn-validator-injection-' . uniqid('', true);
        @unlink($tmp);

        $field = "x\nfile_put_contents(" . var_export($tmp, true) . ", 'owned');\n//";
        $rules = [$field => ['required']];

        $check = Validator::compile($rules);
        $errors = $check([]);

        $this->assertArrayHasKey($field, $errors);
        $this->assertFileDoesNotExist($tmp);
    }

}
