<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware\BearerAuth;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;
use Rxn\Framework\Service\Auth;

final class BearerAuthTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testValidTokenAttachesPrincipalAndCallsNext(): void
    {
        $auth = $this->makeAuth();
        $auth->setResolver(fn (string $t) => $t === 'good' ? ['id' => 7, 'name' => 'Alice'] : null);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer good';

        $mw = new BearerAuth($auth);
        $captured = null;
        $response = $mw->handle($this->bareRequest(), function () use (&$captured): Response {
            $captured = BearerAuth::current();
            return $this->okResponse();
        });

        $this->assertSame(['id' => 7, 'name' => 'Alice'], $captured);
        $this->assertSame(200, $response->getCode());
    }

    public function testCurrentClearedAfterRequest(): void
    {
        $auth = $this->makeAuth();
        $auth->setResolver(fn () => ['id' => 1]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ok';

        $mw = new BearerAuth($auth);
        $mw->handle($this->bareRequest(), fn () => $this->okResponse());

        // Crucial for long-lived workers: principal must not leak
        // into the next request.
        $this->assertNull(BearerAuth::current());
    }

    public function testMissingHeaderReturns401(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $auth = $this->makeAuth();
        $auth->setResolver(fn () => ['id' => 1]);

        $response = (new BearerAuth($auth))
            ->handle($this->bareRequest(), fn () => $this->okResponse());

        $this->assertSame(401, $response->getCode());
        // Must be a real error response so App::render emits
        // application/problem+json — anything less and the framework
        // silently violates its RFC 7807 commitment for auth failures.
        $this->assertTrue($response->isError());
        $problem = $response->toProblemDetails('/test');
        $this->assertSame(401, $problem['status']);
        $this->assertSame('Unauthorized', $problem['title']);
        $this->assertSame('Authentication required', $problem['detail']);
    }

    public function testMalformedHeaderReturns401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $auth = $this->makeAuth();
        $auth->setResolver(fn () => ['id' => 1]);

        $response = (new BearerAuth($auth))
            ->handle($this->bareRequest(), fn () => $this->okResponse());

        $this->assertSame(401, $response->getCode());
    }

    public function testRejectedTokenReturns401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong';
        $auth = $this->makeAuth();
        $auth->setResolver(fn () => null);  // resolver always rejects

        $terminalCalled = false;
        $response = (new BearerAuth($auth))
            ->handle($this->bareRequest(), function () use (&$terminalCalled): Response {
                $terminalCalled = true;
                return $this->okResponse();
            });

        $this->assertFalse($terminalCalled, 'terminal must not run when auth fails');
        $this->assertSame(401, $response->getCode());
    }

    public function testCustomHeaderName(): void
    {
        $_SERVER['HTTP_X_AUTH_TOKEN'] = 'Bearer xyz';
        $auth = $this->makeAuth();
        $auth->setResolver(fn (string $t) => ['token' => $t]);

        $response = (new BearerAuth($auth, headerName: 'X-Auth-Token'))
            ->handle($this->bareRequest(), fn () => $this->okResponse());

        $this->assertSame(200, $response->getCode());
    }

    private function makeAuth(): Auth
    {
        return (new \ReflectionClass(Auth::class))->newInstanceWithoutConstructor();
    }

    private function bareRequest(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }

    private function okResponse(): Response
    {
        $r = (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
        $r->data = ['ok' => true];
        $codeProp = (new \ReflectionClass(Response::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($r, 200);
        return $r;
    }
}
