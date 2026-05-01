<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Error\RequestException;
use Rxn\Framework\Http\Middleware\JsonBody;

final class JsonBodyTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
    }

    private function request(string $method, string $body, string $contentType = '', ?string $contentLength = null): ServerRequestInterface
    {
        $headers = [];
        if ($contentType !== '') {
            $headers['Content-Type'] = $contentType;
        }
        if ($contentLength !== null) {
            $headers['Content-Length'] = $contentLength;
        }
        return new ServerRequest($method, 'http://test.local/', $headers, $body);
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

    public function testDecodesValidJsonIntoPost(): void
    {
        $req = $this->request('POST', '{"name":"ada","age":36}', 'application/json');
        (new JsonBody())->process($req, $this->terminal());

        $this->assertSame('ada', $_POST['name']);
        $this->assertSame(36, $_POST['age']);
    }

    public function testParsedBodyIsAlsoSetOnRequest(): void
    {
        $req = $this->request('POST', '{"k":"v"}', 'application/json');
        $captured = null;
        (new JsonBody())->process($req, $this->terminal(function (ServerRequestInterface $r) use (&$captured) {
            $captured = $r->getParsedBody();
            return new Psr7Response(200);
        }));

        $this->assertSame(['k' => 'v'], $captured);
    }

    public function testGetRequestPassesThroughUntouched(): void
    {
        $req = $this->request('GET', '{"should":"not-decode"}', 'application/json');
        (new JsonBody())->process($req, $this->terminal());

        $this->assertSame([], $_POST);
    }

    public function testMissingContentTypePassesThrough(): void
    {
        $req = $this->request('POST', '');
        (new JsonBody())->process($req, $this->terminal());

        $this->assertSame([], $_POST);
    }

    public function testMismatchedContentTypeThrows415(): void
    {
        $req = $this->request('POST', 'name=ada', 'application/x-www-form-urlencoded');
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(415);
        (new JsonBody())->process($req, $this->terminal());
    }

    public function testInvalidJsonThrows400(): void
    {
        $req = $this->request('POST', '{"unterminated":', 'application/json');
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(400);
        (new JsonBody())->process($req, $this->terminal());
    }

    public function testContentLengthAboveMaxThrows413(): void
    {
        $req = $this->request('POST', '{"ok":true}', 'application/json', '99999999');
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(413);
        (new JsonBody(1024))->process($req, $this->terminal());
    }

    public function testActualBodyAboveMaxThrows413(): void
    {
        $req = $this->request('POST', str_repeat('a', 2048), 'application/json', '0');
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(413);
        (new JsonBody(1024))->process($req, $this->terminal());
    }

    public function testCharsetParameterIsStripped(): void
    {
        $req = $this->request('POST', '{"ok":true}', 'application/json; charset=utf-8');
        (new JsonBody())->process($req, $this->terminal());

        $this->assertTrue($_POST['ok']);
    }

    public function testEmptyBodyPassesThrough(): void
    {
        $req = $this->request('POST', '', 'application/json');
        (new JsonBody())->process($req, $this->terminal());

        $this->assertSame([], $_POST);
    }

    public function testScalarJsonRejectedAs400(): void
    {
        $req = $this->request('POST', '"just a string"', 'application/json');
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(400);
        (new JsonBody())->process($req, $this->terminal());
    }
}
