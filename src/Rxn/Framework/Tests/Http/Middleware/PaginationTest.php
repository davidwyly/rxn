<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware\Pagination as PaginationMiddleware;
use Rxn\Framework\Http\Pagination\Pagination;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

final class PaginationTest extends TestCase
{
    private array $getBackup;
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->getBackup = $_GET;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_SERVER = $this->serverBackup;
    }

    public function testDefaultsWhenNoQueryParams(): void
    {
        $_GET = [];
        $captured = null;
        (new PaginationMiddleware())->handle($this->bareRequest(), function () use (&$captured): Response {
            $captured = Pagination::current();
            return $this->okResponse();
        });
        $this->assertNotNull($captured);
        $this->assertSame(25, $captured->limit);
        $this->assertSame(0, $captured->offset);
        $this->assertSame(1, $captured->page);
    }

    public function testOffsetBasedQuery(): void
    {
        $_GET = ['limit' => '20', 'offset' => '40'];
        $captured = null;
        (new PaginationMiddleware())->handle($this->bareRequest(), function () use (&$captured): Response {
            $captured = Pagination::current();
            return $this->okResponse();
        });
        $this->assertSame(20, $captured->limit);
        $this->assertSame(40, $captured->offset);
        $this->assertSame(3, $captured->page);  // (40 / 20) + 1
    }

    public function testPageBasedQuery(): void
    {
        $_GET = ['page' => '4', 'per_page' => '15'];
        $captured = null;
        (new PaginationMiddleware())->handle($this->bareRequest(), function () use (&$captured): Response {
            $captured = Pagination::current();
            return $this->okResponse();
        });
        $this->assertSame(15, $captured->limit);
        $this->assertSame(45, $captured->offset);  // (4 - 1) * 15
        $this->assertSame(4, $captured->page);
    }

    public function testLimitClampedToMax(): void
    {
        $_GET = ['limit' => '999999'];
        $captured = null;
        (new PaginationMiddleware(maxLimit: 50))->handle($this->bareRequest(), function () use (&$captured): Response {
            $captured = Pagination::current();
            return $this->okResponse();
        });
        $this->assertSame(50, $captured->limit);
    }

    public function testNegativesClampedToSafeMinimums(): void
    {
        $_GET = ['limit' => '-5', 'offset' => '-10'];
        $captured = null;
        (new PaginationMiddleware())->handle($this->bareRequest(), function () use (&$captured): Response {
            $captured = Pagination::current();
            return $this->okResponse();
        });
        $this->assertSame(1, $captured->limit);     // floor at 1
        $this->assertSame(0, $captured->offset);    // floor at 0
    }

    public function testEmitsXTotalCountAndLinkHeaders(): void
    {
        $_GET = ['page' => '2', 'per_page' => '10'];
        $_SERVER['REQUEST_URI'] = '/widgets?page=2&per_page=10';

        $emitted = [];
        $emit    = function (string $h) use (&$emitted) { $emitted[] = $h; };

        $mw = new PaginationMiddleware(emitHeader: $emit);

        // Controller returns total=37 (4 pages of 10).
        $mw->handle($this->bareRequest(), fn () => $this->okResponseWithMeta(['total' => 37]));

        $this->assertContains('X-Total-Count: 37', $emitted);

        // Should emit Link header with first/prev/next/last
        $linkHeaders = array_values(array_filter($emitted, fn ($h) => str_starts_with($h, 'Link: ')));
        $this->assertCount(1, $linkHeaders);
        $this->assertStringContainsString('rel="first"', $linkHeaders[0]);
        $this->assertStringContainsString('rel="prev"',  $linkHeaders[0]);
        $this->assertStringContainsString('rel="next"',  $linkHeaders[0]);
        $this->assertStringContainsString('rel="last"',  $linkHeaders[0]);
        $this->assertStringContainsString('page=4', $linkHeaders[0]); // last page
    }

    public function testFirstPageHasNoPrevLink(): void
    {
        $_GET = ['page' => '1', 'per_page' => '10'];
        $_SERVER['REQUEST_URI'] = '/widgets';
        $emitted = [];
        $mw = new PaginationMiddleware(emitHeader: function (string $h) use (&$emitted) { $emitted[] = $h; });
        $mw->handle($this->bareRequest(), fn () => $this->okResponseWithMeta(['total' => 30]));

        $linkHeaders = array_values(array_filter($emitted, fn ($h) => str_starts_with($h, 'Link: ')));
        $this->assertCount(1, $linkHeaders);
        $this->assertStringNotContainsString('rel="prev"',  $linkHeaders[0]);
        $this->assertStringNotContainsString('rel="first"', $linkHeaders[0]);
        $this->assertStringContainsString('rel="next"', $linkHeaders[0]);
    }

    public function testNoTotalMeansNoHeaders(): void
    {
        $_GET = ['page' => '2'];
        $emitted = [];
        $mw = new PaginationMiddleware(emitHeader: function (string $h) use (&$emitted) { $emitted[] = $h; });
        $mw->handle($this->bareRequest(), fn () => $this->okResponse());
        $this->assertEmpty(array_filter($emitted, fn ($h) => str_starts_with($h, 'X-Total-Count') || str_starts_with($h, 'Link')));
    }

    public function testCurrentClearedAfterRequest(): void
    {
        $_GET = ['page' => '2'];
        (new PaginationMiddleware())->handle($this->bareRequest(), fn () => $this->okResponse());
        $this->assertNull(Pagination::current(), 'must clear after request to avoid leaking into next');
    }

    private function bareRequest(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }

    private function okResponse(): Response
    {
        $r = (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
        $r->data = ['rows' => []];
        $codeProp = (new \ReflectionClass(Response::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($r, 200);
        return $r;
    }

    private function okResponseWithMeta(array $meta): Response
    {
        $r = $this->okResponse();
        $r->meta = $meta;
        return $r;
    }
}
