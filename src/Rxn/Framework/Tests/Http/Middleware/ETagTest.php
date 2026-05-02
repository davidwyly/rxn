<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Middleware\ETag;

final class ETagTest extends TestCase
{
    private function request(string $method = 'GET', array $headers = []): ServerRequestInterface
    {
        return new ServerRequest($method, 'http://test.local/', $headers);
    }

    /**
     * Build a JSON response in the framework's `{data, meta}` envelope —
     * what App::render produces on success. ETag hashes the body bytes,
     * so the envelope shape directly determines the tag.
     */
    private function jsonResponse(mixed $data, int $status = 200): ResponseInterface
    {
        $body = json_encode([
            'data' => $data,
            'meta' => ['success' => true, 'code' => $status],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new Psr7Response($status, ['Content-Type' => 'application/json'], $body);
    }

    private function failure(): ResponseInterface
    {
        return new Psr7Response(500, ['Content-Type' => 'application/problem+json'], '{"status":500}');
    }

    private function terminal(callable $cb): RequestHandlerInterface
    {
        return new class($cb) implements RequestHandlerInterface {
            /** @var callable */
            private $cb;
            public function __construct(callable $cb) { $this->cb = $cb; }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->cb)($request);
            }
        };
    }

    public function testEmitsETagHeaderOnGet(): void
    {
        $response = (new ETag())->process(
            $this->request(),
            $this->terminal(fn () => $this->jsonResponse(['name' => 'ada'])),
        );

        $this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{16}"$/', $response->getHeaderLine('ETag'));
    }

    public function testShortCircuitsWhenIfNoneMatchMatches(): void
    {
        // First pass: grab the ETag by letting the middleware compute it.
        $first = (new ETag())->process(
            $this->request(),
            $this->terminal(fn () => $this->jsonResponse(['x' => 1])),
        );
        $etag = $first->getHeaderLine('ETag');
        $this->assertNotEmpty($etag);

        // Second pass: send it back as If-None-Match and expect 304.
        $terminalHit = false;
        $result = (new ETag())->process(
            $this->request('GET', ['If-None-Match' => $etag]),
            $this->terminal(function () use (&$terminalHit) {
                $terminalHit = true;
                return $this->jsonResponse(['x' => 1]);
            }),
        );

        $this->assertTrue($terminalHit, 'terminal still runs — ETag is post-processing, not a gate');
        $this->assertSame(304, $result->getStatusCode());
        $this->assertSame('', (string)$result->getBody());
    }

    public function testWildcardIfNoneMatchShortCircuits(): void
    {
        $result = (new ETag())->process(
            $this->request('GET', ['If-None-Match' => '*']),
            $this->terminal(fn () => $this->jsonResponse(['x' => 1])),
        );

        $this->assertSame(304, $result->getStatusCode());
    }

    public function testDifferentPayloadDoesNotMatch(): void
    {
        $result = (new ETag())->process(
            $this->request('GET', ['If-None-Match' => 'W/"not-a-real-tag"']),
            $this->terminal(fn () => $this->jsonResponse(['x' => 1])),
        );

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string)$result->getBody(), true);
        $this->assertSame(['x' => 1], $body['data']);
    }

    public function testPostIsPassedThroughWithoutHeader(): void
    {
        $response = (new ETag())->process(
            $this->request('POST'),
            $this->terminal(fn () => $this->jsonResponse(['created' => 1], 201)),
        );

        $this->assertFalse($response->hasHeader('ETag'), 'POST must not get an ETag');
    }

    public function testErrorResponseIsPassedThrough(): void
    {
        $response = (new ETag())->process(
            $this->request(),
            $this->terminal(fn () => $this->failure()),
        );

        $this->assertFalse($response->hasHeader('ETag'), 'failures must not get an ETag');
    }

    public function testEtagIsStableAcrossCalls(): void
    {
        $first  = (new ETag())->process(
            $this->request(),
            $this->terminal(fn () => $this->jsonResponse(['id' => 7, 'name' => 'ada'])),
        );
        $second = (new ETag())->process(
            $this->request(),
            $this->terminal(fn () => $this->jsonResponse(['id' => 7, 'name' => 'ada'])),
        );

        $this->assertSame($first->getHeaderLine('ETag'), $second->getHeaderLine('ETag'));
        $this->assertNotEmpty($first->getHeaderLine('ETag'));
    }
}
