<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Concurrency\HttpClient;
use Rxn\Framework\Concurrency\Scheduler;

/**
 * Unit tests for `HttpClient` that don't require live HTTP.
 *
 * The happy path (real GET against a real server) is covered by
 * `bench/fiber/run.php` which boots backends; here we lock the
 * scheme-rejection guard so a `file://` / `gopher://` / `ftp://`
 * URL never reaches `curl_init`.
 */
final class HttpClientTest extends TestCase
{
    public function testRejectsFileScheme(): void
    {
        // Scheme rejection runs *before* any curl call inside
        // HttpClient::getAsync, so this test deliberately doesn't
        // skip when ext-curl is missing — the guard is what's
        // under test, and it must hold regardless.
        $client = new HttpClient(new Scheduler());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only http/https URLs are allowed.');
        $client->getAsync('file:///etc/hostname');
    }

    public function testRejectsGopherScheme(): void
    {
        $client = new HttpClient(new Scheduler());

        $this->expectException(\InvalidArgumentException::class);
        $client->getAsync('gopher://example.com/');
    }

    public function testRejectsSchemelessUrl(): void
    {
        $client = new HttpClient(new Scheduler());

        $this->expectException(\InvalidArgumentException::class);
        $client->getAsync('example.com/path');
    }

    public function testAcceptsHttpAndHttpsButFailsLaterIfNoCurl(): void
    {
        // We only assert the guard is silent for http/https; the
        // call may then fail on missing curl or network — those
        // are out of scope for this test.
        $client = new HttpClient(new Scheduler());

        if (!extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl not available; cannot exercise the curl branch');
        }

        // No exception from the guard. Promise creation must not
        // throw InvalidArgumentException for these URLs.
        $client->getAsync('http://127.0.0.1:1/will-not-resolve');
        $client->getAsync('https://127.0.0.1:1/will-not-resolve');
        $this->assertTrue(true, 'http/https URLs pass the scheme guard');
    }

    public function testApplyTraceContextNoOpsWhenNoCurrentContext(): void
    {
        $this->resetTraceContextSlots();
        $headers = ['Accept' => 'application/json'];
        $this->assertSame($headers, HttpClient::applyTraceContext($headers));
    }

    public function testApplyTraceContextInjectsTraceparent(): void
    {
        // Drive the middleware so `current()` returns a real context,
        // then verify HttpClient picks it up. End-to-end exercise of
        // the inbound→outbound propagation chain inside one process.
        $this->processInboundTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $headers = HttpClient::applyTraceContext([]);
        $this->assertArrayHasKey('traceparent', $headers);
        $this->assertMatchesRegularExpression(
            '/^00-4bf92f3577b34da6a3ce929d0e0e4736-[0-9a-f]{16}-01$/',
            $headers['traceparent'],
            'Outbound traceparent must keep the same trace-id and flags but advance the parent-id'
        );
        // parent-id must NOT be the inbound parent-id — this server
        // is now the parent of the next hop.
        $parentId = explode('-', $headers['traceparent'])[2];
        $this->assertNotSame('00f067aa0ba902b7', $parentId);
    }

    public function testApplyTraceContextDoesNotOverrideCallerSuppliedHeader(): void
    {
        // If the calling code already set `traceparent` (e.g. in a
        // batch job that's manually threading a trace), HttpClient
        // must not stomp on it.
        $this->processInboundTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $caller = '00-deadbeefdeadbeefdeadbeefdeadbeef-aaaaaaaaaaaaaaaa-00';
        $headers = HttpClient::applyTraceContext(['traceparent' => $caller]);
        $this->assertSame($caller, $headers['traceparent']);
    }

    public function testApplyTraceContextDoesNotOverrideCaseVariantHeader(): void
    {
        // HTTP header names are case-insensitive — `Traceparent` must
        // count as already-set so we don't end up with both casings
        // in the outbound list.
        $this->processInboundTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $caller = '00-deadbeefdeadbeefdeadbeefdeadbeef-aaaaaaaaaaaaaaaa-00';
        $headers = HttpClient::applyTraceContext(['Traceparent' => $caller]);
        $this->assertArrayNotHasKey('traceparent', $headers);
        $this->assertSame($caller, $headers['Traceparent']);
    }

    public function testApplyTraceContextPropagatesTraceState(): void
    {
        $this->processInboundTrace(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            traceState: 'rojo=00f067aa0ba902b7,congo=t61rcWkgMzE',
        );

        $headers = HttpClient::applyTraceContext([]);
        $this->assertSame('rojo=00f067aa0ba902b7,congo=t61rcWkgMzE', $headers['tracestate']);
    }

    public function testApplyTraceContextDropsControlCharsDefensively(): void
    {
        // Defence in depth: the middleware sanitises tracestate on
        // ingress, but a CLI job or test harness could set the
        // static slot directly without going through middleware.
        // HttpClient must not pass a CRLF-bearing value through
        // to curl's raw `"$k: $v"` header builder, where it would
        // smuggle extra headers into the outbound request.
        $this->processInboundTrace(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        );

        // Bypass the middleware's sanitiser — simulate something
        // (test harness, bug, library code) that wrote an unsafe
        // value into the static slot.
        $ref = new \ReflectionClass(\Rxn\Framework\Http\Middleware\TraceContext::class);
        $ref->getProperty('traceState')->setValue(null, "rojo=foo\r\nX-Injected: yes");

        $headers = HttpClient::applyTraceContext([]);
        $this->assertArrayNotHasKey('tracestate', $headers);
    }

    public function testApplyTraceContextDropsOversizeTraceStateDefensively(): void
    {
        $this->processInboundTrace(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        );

        $ref = new \ReflectionClass(\Rxn\Framework\Http\Middleware\TraceContext::class);
        $ref->getProperty('traceState')->setValue(null, str_repeat('a', 600));

        $headers = HttpClient::applyTraceContext([]);
        $this->assertArrayNotHasKey('tracestate', $headers);
    }

    private function processInboundTrace(string $traceparent, ?string $traceState = null): void
    {
        $headers = ['traceparent' => $traceparent];
        if ($traceState !== null) {
            $headers['tracestate'] = $traceState;
        }
        $request = new \Nyholm\Psr7\ServerRequest('GET', 'http://test.local/', $headers);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new \Nyholm\Psr7\Response(200);
            }
        };
        (new \Rxn\Framework\Http\Middleware\TraceContext())->process($request, $handler);
    }

    private function resetTraceContextSlots(): void
    {
        $ref = new \ReflectionClass(\Rxn\Framework\Http\Middleware\TraceContext::class);
        $ref->getProperty('current')->setValue(null, null);
        $ref->getProperty('traceState')->setValue(null, null);
    }
}
