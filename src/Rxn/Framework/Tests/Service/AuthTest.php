<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Service;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Service\Auth;

final class AuthTest extends TestCase
{
    public function testExtractBearerAcceptsStandardForms(): void
    {
        $auth = new Auth();
        $this->assertSame('abc.def.ghi', $auth->extractBearer('Bearer abc.def.ghi'));
        $this->assertSame('abc.def.ghi', $auth->extractBearer('bearer abc.def.ghi'));
        $this->assertSame('abc.def.ghi', $auth->extractBearer('BEARER   abc.def.ghi'));
    }

    public function testExtractBearerRejectsMalformed(): void
    {
        $auth = new Auth();
        $this->assertNull($auth->extractBearer(null));
        $this->assertNull($auth->extractBearer(''));
        $this->assertNull($auth->extractBearer('Basic Zm9vOmJhcg=='));
        $this->assertNull($auth->extractBearer('Bearer'));
    }

    public function testResolveDelegatesToRegisteredCallback(): void
    {
        $auth = new Auth();
        $auth->setResolver(function (string $token): ?array {
            return $token === 'good' ? ['id' => 42, 'email' => 'u@example.com'] : null;
        });

        $this->assertSame(['id' => 42, 'email' => 'u@example.com'], $auth->resolve('good'));
        $this->assertNull($auth->resolve('bad'));
    }

    public function testResolveReturnsNullWithoutResolver(): void
    {
        $auth = new Auth();
        $this->assertFalse($auth->hasResolver());
        $this->assertNull($auth->resolve('anything'));
    }

    public function testResolveHandlesNullToken(): void
    {
        $auth = new Auth();
        $auth->setResolver(fn () => ['should never be returned']);
        $this->assertNull($auth->resolve(null));
        $this->assertNull($auth->resolve(''));
    }
}
