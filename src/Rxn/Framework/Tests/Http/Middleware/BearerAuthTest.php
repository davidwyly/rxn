<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Middleware\BearerAuth;

final class BearerAuthTest extends TestCase
{
    private function request(array $headers = []): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://test.local/', $headers);
    }

    private function terminal(?\Closure $cb = null): RequestHandlerInterface
    {
        $cb ??= fn () => new Psr7Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
        return new class($cb) implements RequestHandlerInterface {
            public function __construct(private \Closure $cb) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->cb)($request);
            }
        };
    }

    public function testValidTokenAttachesPrincipalAndCallsNext(): void
    {
        $resolver = fn (string $t) => $t === 'good' ? ['id' => 7, 'name' => 'Alice'] : null;

        $captured = null;
        $response = (new BearerAuth($resolver))->process(
            $this->request(['Authorization' => 'Bearer good']),
            $this->terminal(function () use (&$captured) {
                $captured = BearerAuth::current();
                return new Psr7Response(200, [], '{"ok":true}');
            }),
        );

        $this->assertSame(['id' => 7, 'name' => 'Alice'], $captured);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCurrentClearedAfterRequest(): void
    {
        $resolver = fn (string $token) => ['id' => 1];

        (new BearerAuth($resolver))->process(
            $this->request(['Authorization' => 'Bearer ok']),
            $this->terminal(),
        );

        // Crucial for long-lived workers: principal must not leak
        // into the next request.
        $this->assertNull(BearerAuth::current());
    }

    public function testMissingHeaderReturns401(): void
    {
        $resolver = fn (string $token) => ['id' => 1];

        $response = (new BearerAuth($resolver))->process(
            $this->request(),
            $this->terminal(),
        );

        $this->assertSame(401, $response->getStatusCode());
        // Must be a Problem Details response so the wire shape
        // matches every other failure path the framework emits —
        // anything less silently violates the RFC 7807 commitment
        // for auth failures.
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(401, $body['status']);
        $this->assertSame('Unauthorized', $body['title']);
        $this->assertSame('Authentication required', $body['detail']);
    }

    public function testMalformedHeaderReturns401(): void
    {
        $resolver = fn (string $token) => ['id' => 1];

        $response = (new BearerAuth($resolver))->process(
            $this->request(['Authorization' => 'Basic dXNlcjpwYXNz']),
            $this->terminal(),
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRejectedTokenReturns401(): void
    {
        $resolver = fn (string $token) => null;  // resolver always rejects

        $terminalCalled = false;
        $response = (new BearerAuth($resolver))->process(
            $this->request(['Authorization' => 'Bearer wrong']),
            $this->terminal(function () use (&$terminalCalled) {
                $terminalCalled = true;
                return new Psr7Response(200);
            }),
        );

        $this->assertFalse($terminalCalled, 'terminal must not run when auth fails');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testEmptyArrayPrincipalIsRejected(): void
    {
        // `[]` from a resolver means "we don't know who you are" —
        // treat it as a rejection rather than letting the request
        // through with an empty principal that downstream code would
        // need to defensively check.
        $resolver = fn (string $token) => [];

        $response = (new BearerAuth($resolver))->process(
            $this->request(['Authorization' => 'Bearer x']),
            $this->terminal(),
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCustomHeaderName(): void
    {
        $resolver = fn (string $t) => ['token' => $t];

        $response = (new BearerAuth($resolver, headerName: 'X-Auth-Token'))->process(
            $this->request(['X-Auth-Token' => 'Bearer xyz']),
            $this->terminal(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
