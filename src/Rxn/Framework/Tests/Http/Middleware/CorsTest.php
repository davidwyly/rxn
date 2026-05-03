<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Middleware\Cors;

final class CorsTest extends TestCase
{
    private function request(string $method = 'GET', array $headers = []): ServerRequestInterface
    {
        return new ServerRequest($method, 'http://test.local/', $headers);
    }

    private function terminal(?\Closure $cb = null): RequestHandlerInterface
    {
        $cb ??= fn () => new Psr7Response(200);
        return new class($cb) implements RequestHandlerInterface {
            public function __construct(private \Closure $cb) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->cb)($request);
            }
        };
    }

    private function make(array $overrides = []): Cors
    {
        return new Cors(
            allowOrigins:     $overrides['allowOrigins']     ?? ['*'],
            allowMethods:     $overrides['allowMethods']     ?? ['GET', 'POST', 'OPTIONS'],
            allowHeaders:     $overrides['allowHeaders']     ?? ['Content-Type'],
            maxAge:           $overrides['maxAge']           ?? 600,
            allowCredentials: $overrides['allowCredentials'] ?? false,
        );
    }

    public function testWildcardOriginEmitsStar(): void
    {
        $response = $this->make()->process($this->request(), $this->terminal());

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testExplicitAllowListEchoesMatchingOrigin(): void
    {
        $response = $this->make(['allowOrigins' => ['https://app.example.com']])
            ->process(
                $this->request('GET', ['Origin' => 'https://app.example.com']),
                $this->terminal(),
            );

        $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testDisallowedOriginOmitsAllowOriginHeader(): void
    {
        $response = $this->make(['allowOrigins' => ['https://app.example.com']])
            ->process(
                $this->request('GET', ['Origin' => 'https://evil.example.com']),
                $this->terminal(),
            );

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testPreflightShortCircuitsWith204(): void
    {
        $terminalHit = false;
        $response = $this->make()->process(
            $this->request('OPTIONS'),
            $this->terminal(function () use (&$terminalHit) {
                $terminalHit = true;
                return new Psr7Response(200);
            }),
        );

        $this->assertFalse($terminalHit, 'preflight must not reach the controller');
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testCredentialsReflectOriginInsteadOfWildcard(): void
    {
        $response = $this->make([
            'allowOrigins'     => ['*'],
            'allowCredentials' => true,
        ])->process(
            $this->request('GET', ['Origin' => 'https://app.example.com']),
            $this->terminal(),
        );

        $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }
}
