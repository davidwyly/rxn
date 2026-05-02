<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Middleware\Pagination as PaginationMiddleware;
use Rxn\Framework\Http\Pagination\Pagination;

final class PaginationTest extends TestCase
{
    private function request(string $path = '/widgets', array $query = []): ServerRequestInterface
    {
        $uri = 'http://test.local' . $path;
        if ($query !== []) {
            $uri .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
        }
        $r = new ServerRequest('GET', $uri);
        return $r->withQueryParams($query);
    }

    private function terminal(?\Closure $cb = null): RequestHandlerInterface
    {
        $cb ??= fn () => $this->jsonResponse(['rows' => []]);
        return new class($cb) implements RequestHandlerInterface {
            public function __construct(private \Closure $cb) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->cb)($request);
            }
        };
    }

    private function jsonResponse(mixed $data, array $meta = ['success' => true, 'code' => 200], int $status = 200): ResponseInterface
    {
        $body = json_encode([
            'data' => $data,
            'meta' => $meta,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new Psr7Response($status, ['Content-Type' => 'application/json'], $body);
    }

    public function testDefaultsWhenNoQueryParams(): void
    {
        $captured = null;
        (new PaginationMiddleware())->process(
            $this->request(),
            $this->terminal(function () use (&$captured) {
                $captured = Pagination::current();
                return $this->jsonResponse(['rows' => []]);
            }),
        );
        $this->assertNotNull($captured);
        $this->assertSame(25, $captured->limit);
        $this->assertSame(0, $captured->offset);
        $this->assertSame(1, $captured->page);
    }

    public function testOffsetBasedQuery(): void
    {
        $captured = null;
        (new PaginationMiddleware())->process(
            $this->request(query: ['limit' => '20', 'offset' => '40']),
            $this->terminal(function () use (&$captured) {
                $captured = Pagination::current();
                return $this->jsonResponse([]);
            }),
        );
        $this->assertSame(20, $captured->limit);
        $this->assertSame(40, $captured->offset);
        $this->assertSame(3, $captured->page);  // (40 / 20) + 1
    }

    public function testPageBasedQuery(): void
    {
        $captured = null;
        (new PaginationMiddleware())->process(
            $this->request(query: ['page' => '4', 'per_page' => '15']),
            $this->terminal(function () use (&$captured) {
                $captured = Pagination::current();
                return $this->jsonResponse([]);
            }),
        );
        $this->assertSame(15, $captured->limit);
        $this->assertSame(45, $captured->offset);  // (4 - 1) * 15
        $this->assertSame(4, $captured->page);
    }

    public function testLimitClampedToMax(): void
    {
        $captured = null;
        (new PaginationMiddleware(maxLimit: 50))->process(
            $this->request(query: ['limit' => '999999']),
            $this->terminal(function () use (&$captured) {
                $captured = Pagination::current();
                return $this->jsonResponse([]);
            }),
        );
        $this->assertSame(50, $captured->limit);
    }

    public function testNegativesClampedToSafeMinimums(): void
    {
        $captured = null;
        (new PaginationMiddleware())->process(
            $this->request(query: ['limit' => '-5', 'offset' => '-10']),
            $this->terminal(function () use (&$captured) {
                $captured = Pagination::current();
                return $this->jsonResponse([]);
            }),
        );
        $this->assertSame(1, $captured->limit);     // floor at 1
        $this->assertSame(0, $captured->offset);    // floor at 0
    }

    public function testEmitsXTotalCountAndLinkHeaders(): void
    {
        // Controller returns total=37 (4 pages of 10).
        $response = (new PaginationMiddleware())->process(
            $this->request('/widgets', ['page' => '2', 'per_page' => '10']),
            $this->terminal(fn () => $this->jsonResponse(['rows' => []], ['total' => 37])),
        );

        $this->assertSame('37', $response->getHeaderLine('X-Total-Count'));

        // Link header with first/prev/next/last
        $link = $response->getHeaderLine('Link');
        $this->assertStringContainsString('rel="first"', $link);
        $this->assertStringContainsString('rel="prev"',  $link);
        $this->assertStringContainsString('rel="next"',  $link);
        $this->assertStringContainsString('rel="last"',  $link);
        $this->assertStringContainsString('page=4', $link); // last page
    }

    public function testFirstPageHasNoPrevLink(): void
    {
        $response = (new PaginationMiddleware())->process(
            $this->request('/widgets', ['page' => '1', 'per_page' => '10']),
            $this->terminal(fn () => $this->jsonResponse(['rows' => []], ['total' => 30])),
        );

        $link = $response->getHeaderLine('Link');
        $this->assertStringNotContainsString('rel="prev"',  $link);
        $this->assertStringNotContainsString('rel="first"', $link);
        $this->assertStringContainsString('rel="next"', $link);
    }

    public function testNoTotalMeansNoHeaders(): void
    {
        $response = (new PaginationMiddleware())->process(
            $this->request(query: ['page' => '2']),
            $this->terminal(fn () => $this->jsonResponse(['rows' => []], ['success' => true])),
        );

        $this->assertFalse($response->hasHeader('X-Total-Count'));
        $this->assertFalse($response->hasHeader('Link'));
    }

    public function testCurrentClearedAfterRequest(): void
    {
        (new PaginationMiddleware())->process(
            $this->request(query: ['page' => '2']),
            $this->terminal(),
        );
        $this->assertNull(Pagination::current(), 'must clear after request to avoid leaking into next');
    }
}
