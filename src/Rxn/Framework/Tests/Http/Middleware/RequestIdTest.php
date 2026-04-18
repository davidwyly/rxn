<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware\RequestId;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

final class RequestIdTest extends TestCase
{
    /** @var string[] */
    private array $headers = [];

    protected function setUp(): void
    {
        $this->headers = [];
        unset($_SERVER['HTTP_X_REQUEST_ID']);
    }

    private function request(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }

    private function terminalResponse(): Response
    {
        return (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
    }

    private function make(): RequestId
    {
        return new RequestId(function (string $h) { $this->headers[] = $h; });
    }

    public function testGeneratesUuidWhenMissing(): void
    {
        $mw = $this->make();
        $mw->handle($this->request(), fn () => $this->terminalResponse());

        $id = RequestId::current();
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
        $this->assertContains("X-Request-ID: $id", $this->headers);
    }

    public function testHonoursIncomingId(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'req-0123456789abcdef';

        $mw = $this->make();
        $mw->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertSame('req-0123456789abcdef', RequestId::current());
        $this->assertContains('X-Request-ID: req-0123456789abcdef', $this->headers);
    }

    public function testRejectsMalformedIncomingId(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = "id\r\nInjected-Header: evil";

        $mw = $this->make();
        $mw->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertNotSame("id\r\nInjected-Header: evil", RequestId::current());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/',
            RequestId::current()
        );
    }

    public function testRejectsTooShortIncomingId(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'short';

        $mw = $this->make();
        $mw->handle($this->request(), fn () => $this->terminalResponse());

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
