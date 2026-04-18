<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware\Cors;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

final class CorsTest extends TestCase
{
    /** @var string[] */
    private array $headers = [];
    private ?int $status   = null;

    protected function setUp(): void
    {
        $this->headers = [];
        $this->status  = null;
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['REQUEST_METHOD']);
    }

    private function request(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }

    private function make(array $overrides = []): Cors
    {
        return new Cors(
            allowOrigins:     $overrides['allowOrigins']     ?? ['*'],
            allowMethods:     $overrides['allowMethods']     ?? ['GET', 'POST', 'OPTIONS'],
            allowHeaders:     $overrides['allowHeaders']     ?? ['Content-Type'],
            maxAge:           $overrides['maxAge']           ?? 600,
            allowCredentials: $overrides['allowCredentials'] ?? false,
            emitHeader:       function (string $h) { $this->headers[] = $h; },
            emitStatus:       function (int $code) { $this->status = $code; },
        );
    }

    public function testWildcardOriginEmitsStar(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $cors = $this->make();
        $cors->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertContains('Access-Control-Allow-Origin: *', $this->headers);
        $this->assertContains('Access-Control-Max-Age: 600', $this->headers);
    }

    public function testExplicitAllowListEchoesMatchingOrigin(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ORIGIN']    = 'https://app.example.com';

        $cors = $this->make(['allowOrigins' => ['https://app.example.com']]);
        $cors->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertContains('Access-Control-Allow-Origin: https://app.example.com', $this->headers);
        $this->assertContains('Vary: Origin', $this->headers);
    }

    public function testDisallowedOriginOmitsAllowOriginHeader(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ORIGIN']    = 'https://evil.example.com';

        $cors = $this->make(['allowOrigins' => ['https://app.example.com']]);
        $cors->handle($this->request(), fn () => $this->terminalResponse());

        foreach ($this->headers as $h) {
            $this->assertStringNotContainsString('Access-Control-Allow-Origin:', $h);
        }
    }

    public function testPreflightShortCircuitsWith204(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $terminalHit = false;

        $cors   = $this->make();
        $result = $cors->handle($this->request(), function () use (&$terminalHit) {
            $terminalHit = true;
            return $this->terminalResponse();
        });

        $this->assertFalse($terminalHit, 'preflight must not reach the controller');
        $this->assertSame(204, $this->status);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($result->isRendered());
    }

    public function testCredentialsReflectOriginInsteadOfWildcard(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ORIGIN']    = 'https://app.example.com';

        $cors = $this->make([
            'allowOrigins'     => ['*'],
            'allowCredentials' => true,
        ]);
        $cors->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertContains('Access-Control-Allow-Origin: https://app.example.com', $this->headers);
        $this->assertContains('Access-Control-Allow-Credentials: true', $this->headers);
    }

    private function terminalResponse(): Response
    {
        return (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
    }
}
