<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Middleware\TraceContext;
use Rxn\Framework\Http\Tracing\TraceContext as TraceCtx;

/**
 * Middleware tests cover the request-scoped behaviour: incoming
 * header parsing, fallback generation, request attribute exposure,
 * static `current()` slot, response echo, and tracestate
 * pass-through.
 *
 * The wire-format rules live in `TraceContextTest`
 * (Tests\Http\Tracing) — this file doesn't re-test parsing details.
 */
final class TraceContextTest extends TestCase
{
    private const VALID = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

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

    public function testGeneratesContextWhenHeaderAbsent(): void
    {
        $response = (new TraceContext())->process($this->request(), $this->terminal());

        $current = TraceContext::current();
        $this->assertNotNull($current);
        // Echoed back as a valid traceparent.
        $echoed = $response->getHeaderLine('traceparent');
        $this->assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/',
            $echoed
        );
        $this->assertSame($current->toHeader(), $echoed);
    }

    public function testHonoursIncomingValidHeader(): void
    {
        $response = (new TraceContext())->process(
            $this->request(['traceparent' => self::VALID]),
            $this->terminal(),
        );

        $current = TraceContext::current();
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $current->traceId);
        $this->assertSame('00f067aa0ba902b7', $current->parentId);
        // Echoed verbatim — the server doesn't change parent-id on
        // the response (parent-id changes only on outbound calls
        // the server makes).
        $this->assertSame(self::VALID, $response->getHeaderLine('traceparent'));
    }

    public function testGeneratesFreshContextWhenIncomingHeaderMalformed(): void
    {
        $response = (new TraceContext())->process(
            $this->request(['traceparent' => 'not-a-valid-traceparent']),
            $this->terminal(),
        );

        $current = TraceContext::current();
        $this->assertNotNull($current);
        $this->assertNotSame('not-a-valid-traceparent', $response->getHeaderLine('traceparent'));
        $this->assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/',
            $response->getHeaderLine('traceparent')
        );
    }

    public function testExposesContextOnRequestAttribute(): void
    {
        $captured = null;
        $handler = new class($captured) implements RequestHandlerInterface {
            public function __construct(private mixed &$out) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->out = $request->getAttribute(TraceContext::REQUEST_ATTR);
                return new Psr7Response(200);
            }
        };

        (new TraceContext())->process(
            $this->request(['traceparent' => self::VALID]),
            $handler,
        );

        $this->assertInstanceOf(TraceCtx::class, $captured);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $captured->traceId);
    }

    public function testPropagatesTraceStateVerbatim(): void
    {
        $state = 'congo=lZWRzIHRoNhcm5hbCBwbHVtZQ,rojo=00f067aa0ba902b7';
        $response = (new TraceContext())->process(
            $this->request([
                'traceparent' => self::VALID,
                'tracestate'  => $state,
            ]),
            $this->terminal(),
        );

        $this->assertSame($state, $response->getHeaderLine('tracestate'));
        $this->assertSame($state, TraceContext::currentTraceState());
    }

    public function testDropsOversizedTraceState(): void
    {
        // Spec: tracestate values longer than 512 chars MAY be
        // dropped to avoid emitting a header gateways will reject.
        $oversized = str_repeat('vendor=' . str_repeat('a', 16) . ',', 50);
        $this->assertGreaterThan(512, strlen($oversized));

        $response = (new TraceContext())->process(
            $this->request([
                'traceparent' => self::VALID,
                'tracestate'  => $oversized,
            ]),
            $this->terminal(),
        );

        $this->assertSame('', $response->getHeaderLine('tracestate'));
        $this->assertNull(TraceContext::currentTraceState());
    }

    public function testCurrentReturnsNullBeforeMiddlewareRuns(): void
    {
        // Force the slot back to null via reflection so this test
        // is independent of run order. Same posture as the static
        // slot in RequestId — sync-only, scoped to one request.
        $ref = new \ReflectionClass(TraceContext::class);
        $prop = $ref->getProperty('current');
        $prop->setValue(null, null);
        $stateProp = $ref->getProperty('traceState');
        $stateProp->setValue(null, null);

        $this->assertNull(TraceContext::current());
        $this->assertNull(TraceContext::currentTraceState());
    }
}
