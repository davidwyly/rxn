<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Error\RequestException;
use Rxn\Framework\Http\Middleware\JsonBody;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

final class JsonBodyTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH']);
    }

    private function request(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }

    private function terminalResponse(): Response
    {
        return (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
    }

    private function make(string $body, int $maxBytes = 1048576): JsonBody
    {
        return new JsonBody($maxBytes, fn (int $_maxBytes): string => $body);
    }

    public function testDecodesValidJsonIntoPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/json';

        $mw = $this->make('{"name":"ada","age":36}');
        $mw->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertSame('ada', $_POST['name']);
        $this->assertSame(36, $_POST['age']);
    }

    public function testGetRequestPassesThroughUntouched(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_TYPE']   = 'application/json';

        $mw = $this->make('{"should":"not-decode"}');
        $mw->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertSame([], $_POST);
    }

    public function testMissingContentTypePassesThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        // No CONTENT_TYPE set — nothing to decode, no failure.

        $mw = $this->make('');
        $mw->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertSame([], $_POST);
    }

    public function testMismatchedContentTypeThrows415(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/x-www-form-urlencoded';

        $mw = $this->make('name=ada');
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(415);
        $mw->handle($this->request(), fn () => $this->terminalResponse());
    }

    public function testInvalidJsonThrows400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/json';

        $mw = $this->make('{"unterminated":');
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(400);
        $mw->handle($this->request(), fn () => $this->terminalResponse());
    }

    public function testContentLengthAboveMaxThrows413(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/json';
        $_SERVER['CONTENT_LENGTH'] = '99999999';

        $mw = $this->make('{"ok":true}', 1024);
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(413);
        $mw->handle($this->request(), fn () => $this->terminalResponse());
    }

    public function testActualBodyAboveMaxThrows413(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/json';
        $_SERVER['CONTENT_LENGTH'] = '0'; // lies about its size

        $mw = $this->make(str_repeat('a', 2048), 1024);
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(413);
        $mw->handle($this->request(), fn () => $this->terminalResponse());
    }

    public function testCharsetParameterIsStripped(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/json; charset=utf-8';

        $mw = $this->make('{"ok":true}');
        $mw->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertTrue($_POST['ok']);
    }

    public function testEmptyBodyPassesThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/json';

        $mw = $this->make('');
        $mw->handle($this->request(), fn () => $this->terminalResponse());

        $this->assertSame([], $_POST);
    }

    public function testScalarJsonRejectedAs400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/json';

        $mw = $this->make('"just a string"');
        $this->expectException(RequestException::class);
        $this->expectExceptionCode(400);
        $mw->handle($this->request(), fn () => $this->terminalResponse());
    }
}
