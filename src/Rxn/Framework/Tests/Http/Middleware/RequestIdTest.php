<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Middleware\RequestId;

final class RequestIdTest extends TestCase
{
    private function request(array $headers = []): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://test.local/', $headers);
    }

    private function terminal(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
    }

    public function testGeneratesUuidWhenMissing(): void
    {
        $mw = new RequestId();
        $response = $mw->process($this->request(), $this->terminal());

        $id = RequestId::current();
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
        $this->assertSame($id, $response->getHeaderLine('X-Request-ID'));
    }

    public function testHonoursIncomingId(): void
    {
        $mw = new RequestId();
        $response = $mw->process(
            $this->request(['X-Request-ID' => 'req-0123456789abcdef']),
            $this->terminal(),
        );

        $this->assertSame('req-0123456789abcdef', RequestId::current());
        $this->assertSame('req-0123456789abcdef', $response->getHeaderLine('X-Request-ID'));
    }

    public function testRejectsMalformedIncomingId(): void
    {
        // Note: Nyholm rejects CRLF-bearing header values at PSR-7
        // construction, so the framework's first line of defence is
        // PSR-7 itself. This test verifies the *second* line — that
        // values which pass PSR-7 (no CRLF) but contain other risky
        // characters (whitespace, semicolons) are still rejected by
        // the middleware's grammar check.
        $mw = new RequestId();
        $mw->process(
            $this->request(['X-Request-ID' => 'has spaces and ; semicolons']),
            $this->terminal(),
        );

        $this->assertNotSame('has spaces and ; semicolons', RequestId::current());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/',
            RequestId::current()
        );
    }

    public function testRejectsTooShortIncomingId(): void
    {
        $mw = new RequestId();
        $mw->process(
            $this->request(['X-Request-ID' => 'short']),
            $this->terminal(),
        );

        // Must fall back to a freshly-minted UUID.
        $this->assertNotSame('short', RequestId::current());
    }

    public function testGenerateUuidProducesV4Shape(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            RequestId::generateUuid()
        );
    }
}
