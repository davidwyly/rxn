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
}
