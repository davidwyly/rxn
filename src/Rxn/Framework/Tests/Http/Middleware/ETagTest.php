<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware\ETag;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

final class ETagTest extends TestCase
{
    /** @var string[] */
    private array $headers = [];
    private ?int $status   = null;

    protected function setUp(): void
    {
        $this->headers = [];
        $this->status  = null;
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_IF_NONE_MATCH']);
    }

    private function request(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }

    private function response(mixed $data, int $code = 200): Response
    {
        return (new Response())->getSuccess($data);
    }

    private function failure(): Response
    {
        return (new Response())->getFailure(new \Exception('nope', 500));
    }

    private function make(): ETag
    {
        return new ETag(
            emitHeader: function (string $h) { $this->headers[] = $h; },
            emitStatus: function (int $c) { $this->status = $c; },
        );
    }

    public function testEmitsETagHeaderOnGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $mw = $this->make();
        $mw->handle($this->request(), fn () => $this->response(['name' => 'ada']));

        $has = false;
        foreach ($this->headers as $h) {
            if (str_starts_with($h, 'ETag: W/"')) {
                $has = true;
            }
        }
        $this->assertTrue($has, 'expected a weak ETag header');
    }

    public function testShortCircuitsWhenIfNoneMatchMatches(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // First pass: grab the ETag by letting the middleware compute it.
        $mw1 = $this->make();
        $mw1->handle($this->request(), fn () => $this->response(['x' => 1]));
        $etag = null;
        foreach ($this->headers as $h) {
            if (str_starts_with($h, 'ETag: ')) {
                $etag = substr($h, 6);
            }
        }
        $this->assertNotNull($etag);

        // Second pass: send it back as If-None-Match and expect 304.
        $this->headers = [];
        $this->status  = null;
        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        $terminalHit = false;

        $mw2 = $this->make();
        $result = $mw2->handle($this->request(), function () use (&$terminalHit) {
            $terminalHit = true;
            return $this->response(['x' => 1]);
        });

        $this->assertTrue($terminalHit, 'terminal still runs — ETag is post-processing, not a gate');
        $this->assertSame(304, $this->status);
        $this->assertSame(304, $result->getCode());
        $this->assertNull($result->data);
    }

    public function testWildcardIfNoneMatchShortCircuits(): void
    {
        $_SERVER['REQUEST_METHOD']     = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '*';

        $mw     = $this->make();
        $result = $mw->handle($this->request(), fn () => $this->response(['x' => 1]));

        $this->assertSame(304, $this->status);
        $this->assertSame(304, $result->getCode());
    }

    public function testDifferentPayloadDoesNotMatch(): void
    {
        $_SERVER['REQUEST_METHOD']     = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = 'W/"not-a-real-tag"';

        $mw = $this->make();
        $result = $mw->handle($this->request(), fn () => $this->response(['x' => 1]));

        $this->assertNull($this->status);
        $this->assertSame(200, $result->getCode());
        $this->assertSame(['x' => 1], $result->data);
    }

    public function testPostIsPassedThroughWithoutHeader(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $mw = $this->make();
        $mw->handle($this->request(), fn () => $this->response(['created' => 1]));

        $this->assertNull($this->status);
        $this->assertFalse($this->hasEtagHeader(), 'POST must not get an ETag');
    }

    public function testErrorResponseIsPassedThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $mw = $this->make();
        $mw->handle($this->request(), fn () => $this->failure());

        $this->assertNull($this->status);
        $this->assertFalse($this->hasEtagHeader(), 'failures must not get an ETag');
    }

    private function hasEtagHeader(): bool
    {
        foreach ($this->headers as $h) {
            if (stripos($h, 'ETag') === 0) {
                return true;
            }
        }
        return false;
    }

    public function testEtagIsStableAcrossCalls(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $first  = null;
        $second = null;
        foreach (['first', 'second'] as $pass) {
            $this->headers = [];
            $mw = $this->make();
            $mw->handle($this->request(), fn () => $this->response(['id' => 7, 'name' => 'ada']));
            foreach ($this->headers as $h) {
                if (str_starts_with($h, 'ETag: ')) {
                    ${$pass} = $h;
                }
            }
        }
        $this->assertNotNull($first);
        $this->assertSame($first, $second, 'same payload must produce the same ETag');
    }
}
