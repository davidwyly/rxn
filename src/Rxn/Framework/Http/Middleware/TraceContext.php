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
        $incoming       = $request->getHeaderLine('traceparent');
        $inboundContext = $incoming !== '' ? TraceCtx::fromHeader($incoming) : null;
        $context        = $inboundContext ?? TraceCtx::generate();

        // Per W3C spec: `tracestate` is meaningful only when paired
        // with a valid inbound `traceparent`. If we generated a
        // fresh context (no inbound traceparent or it was malformed),
        // discard any vendor state — propagating it would attach
        // stale metadata to a brand-new trace that's no longer
        // related to the upstream context. Plus: validate against
        // header-injection attacks (CRLF / control characters)
        // before storing or echoing it.
        self::$traceState = $inboundContext !== null
            ? self::sanitiseTraceState($request->getHeaderLine('tracestate'))
            : null;

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

    /**
     * Validate an inbound `tracestate` value before we trust it
     * enough to echo it on the response or stash it for outbound
     * propagation. Three rules:
     *
     *   1. Non-empty.
     *   2. ≤ 512 characters (W3C MAY-drop threshold; gateways
     *      commonly reject larger headers).
     *   3. No ASCII control characters (0x00-0x1f, 0x7f). The
     *      W3C grammar restricts list-member values to printable
     *      ASCII anyway; rejecting CTL chars defends against
     *      `\r\n` header-injection attacks before any downstream
     *      consumer sees the value.
     *
     * Returns the value if it passes; null to indicate "drop".
     * Same posture as oversized values — degrade gracefully.
     *
     * Public so HttpClient (and tests) can apply the same rule
     * as a defence-in-depth check on the static slot, which
     * non-HTTP entrypoints could write to without going through
     * `process()`.
     */
    public static function sanitiseTraceState(string $value): ?string
    {
        if ($value === '' || strlen($value) > 512) {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        return $value;
    }
}
