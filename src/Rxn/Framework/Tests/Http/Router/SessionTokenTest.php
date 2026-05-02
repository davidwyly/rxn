<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Router;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Router\Session;

final class SessionTokenTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsGeneratedOnce(): void
    {
        $first  = Session::token();
        $second = Session::token();

        $this->assertSame($first, $second, 'token() should return the same value within a session');
        $this->assertSame(64, strlen($first), 'token should be 32 bytes hex-encoded (64 chars)');
    }

    public function testValidateAcceptsMatchingToken(): void
    {
        $token = Session::token();
        $this->assertTrue(Session::validateToken($token));
    }

    public function testValidateRejectsMismatch(): void
    {
        Session::token();
        $this->assertFalse(Session::validateToken('not-the-token'));
    }

    public function testValidateRejectsWhenNoTokenIssued(): void
    {
        $this->assertFalse(Session::validateToken('whatever'));
    }

    public function testValidateRejectsNonStringSubmittedToken(): void
    {
        Session::token();
        $this->assertFalse(Session::validateToken(['token']));
    }
}
