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
}
