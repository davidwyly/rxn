<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Tracing\TraceContext as TraceCtx;

/**
 * W3C Trace Context middleware. Honours an incoming `traceparent`
 * header when it parses cleanly; mints a fresh context otherwise.
 * The active context is stashed via `TraceContext::current()` so
 * downstream code (notably `Concurrency\HttpClient`) can pick it
 * up for outbound propagation.
 *
 * The header is echoed on the response so calling services can
 * verify trace continuity.
 *
 *   $pipeline = new Pipeline([
 *       new TraceContext(),
 *       // ... other middleware
 *   ]);
 *
 * Sync-only by design — same posture as `RequestId` and
 * `BearerAuth`: the static slot scopes to one request via the
 * single-threaded PHP request lifecycle.
 *
 * **W3C compliance.** This implementation reads `traceparent` and
 * propagates it back on the response. `tracestate` (the vendor-
 * specific key=value extension list) is propagated verbatim if
 * present — opaque pass-through, no parsing. That matches the spec's
 * MUST-propagate-on-receipt rule.
 */
final class TraceContext implements MiddlewareInterface
{
    public const REQUEST_ATTR = 'rxn.trace_context';

    private static ?TraceCtx $current = null;
    private static ?string $traceState = null;

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $incoming = $request->getHeaderLine('traceparent');
        $context  = $incoming !== ''
            ? (TraceCtx::fromHeader($incoming) ?? TraceCtx::generate())
            : TraceCtx::generate();

        // Vendor-specific tracestate is opaque to us — propagate
        // verbatim. Per spec, max length 512 chars; longer values
        // are dropped (degrades gracefully rather than emitting an
        // oversized header that gateways may reject).
        $rawState = $request->getHeaderLine('tracestate');
        self::$traceState = ($rawState !== '' && strlen($rawState) <= 512) ? $rawState : null;

        self::$current = $context;
        $request = $request->withAttribute(self::REQUEST_ATTR, $context);

        try {
            $response = $handler->handle($request);
        } finally {
            // Don't clear $current here — the response still being
            // rendered may need it (Logger correlation, etc.).
            // Reassignment on the next request resets the slot.
        }

        $response = $response->withHeader('traceparent', $context->toHeader());
        if (self::$traceState !== null) {
            $response = $response->withHeader('tracestate', self::$traceState);
        }
        return $response;
    }

    /**
     * The active trace context, or null when the middleware never
     * ran. Sync-only — see the matching note on `RequestId::current()`.
     */
    public static function current(): ?TraceCtx
    {
        return self::$current;
    }

    /**
     * Vendor-specific `tracestate` header value as it arrived (if
     * any). Opaque to the framework; available so apps that need to
     * forward it on outbound calls can read it.
     */
    public static function currentTraceState(): ?string
    {
        return self::$traceState;
    }
}
