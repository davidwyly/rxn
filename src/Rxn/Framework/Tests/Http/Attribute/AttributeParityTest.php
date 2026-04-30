<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Attribute\Date;
use Rxn\Framework\Http\Attribute\Email;
use Rxn\Framework\Http\Attribute\EndsWith;
use Rxn\Framework\Http\Attribute\Json;
use Rxn\Framework\Http\Attribute\NotBlank;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Attribute\StartsWith;
use Rxn\Framework\Http\Attribute\Url;
use Rxn\Framework\Http\Attribute\Uuid;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Binding\ValidationException;

/**
 * Each attribute lives in two paths — the runtime `Binder::bind`
 * loop (which calls `$attr->newInstance()->validate($cast)`) and
 * the compiled `Binder::compileFor` path (which inlines a
 * specialised check per attribute class). These tests assert
 * both paths agree on every shape, so the two stay locked in step
 * across future changes.
 */
final class AttributeParityTest extends TestCase
{
    public function testEmailAttributeBothPaths(): void
    {
        $this->assertSame(null, (new Email())->validate('a@b.com'));
        $this->assertNotNull((new Email())->validate('not email'));

        $this->assertParity(EmailDto::class,
            valid:   ['email' => 'a@b.com'],
            invalid: ['email' => 'not email'],
            field:   'email',
        );
    }

    public function testUrlAttributeBothPaths(): void
    {
        $this->assertSame(null, (new Url())->validate('https://example.com'));
        $this->assertNotNull((new Url())->validate('not a url'));

        $this->assertParity(UrlDto::class,
            valid:   ['site' => 'https://example.com'],
            invalid: ['site' => 'not a url'],
            field:   'site',
        );
    }

    public function testUuidAttributeBothPaths(): void
    {
        $u = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertSame(null, (new Uuid())->validate($u));
        $this->assertSame(null, (new Uuid())->validate(strtoupper($u)));
        $this->assertNotNull((new Uuid())->validate('not a uuid'));

        $this->assertParity(UuidDto::class,
            valid:   ['id' => $u],
            invalid: ['id' => 'not-a-uuid'],
            field:   'id',
        );
    }

    public function testJsonAttributeBothPaths(): void
    {
        $this->assertSame(null, (new Json())->validate('{"a":1}'));
        $this->assertSame(null, (new Json())->validate('[1,2,3]'));
        $this->assertNotNull((new Json())->validate('not json'));

        $this->assertParity(JsonDto::class,
            valid:   ['blob' => '{"a":1}'],
            invalid: ['blob' => 'not json'],
            field:   'blob',
        );
    }

    public function testDateAttributeBothPaths(): void
    {
        $this->assertSame(null, (new Date())->validate('2026-04-29'));
        $this->assertNotNull((new Date())->validate('2026-13-01'));    // bad month
        $this->assertNotNull((new Date())->validate('2024-02-30'));    // phantom day
        $this->assertNotNull((new Date())->validate('04/29/2026'));    // wrong format
        $this->assertNotNull((new Date())->validate('tomorrow'));      // strtotime-ism

        $this->assertParity(DateDto::class,
            valid:   ['birthday' => '1990-01-15'],
            invalid: ['birthday' => 'tomorrow'],
            field:   'birthday',
        );
    }

    public function testNotBlankAttributeBothPaths(): void
    {
        $this->assertSame(null, (new NotBlank())->validate('hi'));
        $this->assertNotNull((new NotBlank())->validate('   '));
        $this->assertNotNull((new NotBlank())->validate("\t\n"));

        // NotBlank needs to fire on space-only — but space-only makes
        // it past Required's "empty string" check too. Verify the
        // compiled and runtime paths behave the same: both should
        // report the NotBlank error for "   ".
        // (Required fires on `'' / null / missing`, NotBlank fires on
        // `whitespace-only present strings` — different layers.)
        $bag = ['name' => 'a'];           // valid
        $bagBlank = ['name' => '   '];    // not blank fails

        // Runtime
        $this->assertNotNull(Binder::bind(NotBlankDto::class, $bag));
        try {
            Binder::bind(NotBlankDto::class, $bagBlank);
            $this->fail('runtime: expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([['field' => 'name', 'message' => 'must not be blank']], $e->errors());
        }
        // Compiled
        $bind = Binder::compileFor(NotBlankDto::class);
        $this->assertNotNull($bind($bag));
        try {
            $bind($bagBlank);
            $this->fail('compiled: expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([['field' => 'name', 'message' => 'must not be blank']], $e->errors());
        }
    }

    public function testStartsWithAttributeBothPaths(): void
    {
        $this->assertSame(null, (new StartsWith('user_'))->validate('user_alice'));
        $this->assertNotNull((new StartsWith('user_'))->validate('alice'));

        $this->assertParity(StartsWithDto::class,
            valid:   ['username' => 'user_alice'],
            invalid: ['username' => 'alice'],
            field:   'username',
        );
    }

    public function testEndsWithAttributeBothPaths(): void
    {
        $this->assertSame(null, (new EndsWith('.com'))->validate('example.com'));
        $this->assertNotNull((new EndsWith('.com'))->validate('example.org'));

        $this->assertParity(EndsWithDto::class,
            valid:   ['domain' => 'example.com'],
            invalid: ['domain' => 'example.org'],
            field:   'domain',
        );
    }

    /**
     * @param array<string, mixed> $valid
     * @param array<string, mixed> $invalid
     * @param class-string<RequestDto> $class
     */
    private function assertParity(string $class, array $valid, array $invalid, string $field): void
    {
        // Runtime path
        $runtimeOk = Binder::bind($class, $valid);
        $this->assertNotNull($runtimeOk);
        $runtimeErrors = null;
        try {
            Binder::bind($class, $invalid);
        } catch (ValidationException $e) {
            $runtimeErrors = $e->errors();
        }
        $this->assertNotNull($runtimeErrors, 'runtime path did not fail on invalid input');

        // Compiled path
        $bind = Binder::compileFor($class);
        $compiledOk = $bind($valid);
        $this->assertNotNull($compiledOk);
        $compiledErrors = null;
        try {
            $bind($invalid);
        } catch (ValidationException $e) {
            $compiledErrors = $e->errors();
        }
        $this->assertNotNull($compiledErrors, 'compiled path did not fail on invalid input');

        // Same errors, same shape, same order.
        $this->assertSame($runtimeErrors, $compiledErrors,
            'runtime and compiled paths diverged for ' . $class);
        $fields = array_column($runtimeErrors, 'field');
        $this->assertContains($field, $fields);
    }
}

// ---- Fixture DTOs (one per attribute, narrow on purpose) ----

final class EmailDto implements RequestDto      { #[Required] #[Email]      public string $email; }
final class UrlDto implements RequestDto        { #[Required] #[Url]        public string $site; }
final class UuidDto implements RequestDto       { #[Required] #[Uuid]       public string $id; }
final class JsonDto implements RequestDto       { #[Required] #[Json]       public string $blob; }
final class DateDto implements RequestDto       { #[Required] #[Date]       public string $birthday; }
final class NotBlankDto implements RequestDto   { #[Required] #[NotBlank]   public string $name; }
final class StartsWithDto implements RequestDto { #[Required] #[StartsWith('user_')] public string $username; }
final class EndsWithDto implements RequestDto   { #[Required] #[EndsWith('.com')]    public string $domain; }
