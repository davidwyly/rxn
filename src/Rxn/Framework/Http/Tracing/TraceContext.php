<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Tracing;

/**
 * Value object representing a W3C Trace Context — the standard
 * cross-vendor protocol for correlating a logical request across
 * service hops. Lives at https://www.w3.org/TR/trace-context/.
 *
 * The header format on the wire:
 *
 *   traceparent: 00-{trace-id:32hex}-{parent-id:16hex}-{flags:2hex}
 *
 * Example:
 *
 *   traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
 *
 * - **trace-id**: 16 random bytes (32 hex chars). Stays constant
 *   across every service hop in a logical request. Cannot be all
 *   zeros (the spec calls that "invalid").
 * - **parent-id**: 8 random bytes (16 hex chars). Identifies the
 *   *caller* — each service generates a fresh parent-id when it
 *   makes an outbound call, so the receiving service knows "I was
 *   called by parent X." Cannot be all zeros.
 * - **flags**: 1 byte (2 hex chars). Bit 0 = sampled. Other bits
 *   reserved.
 * - **version**: currently `00`. Implementations MUST accept higher
 *   versions on read (the spec mandates forward compatibility) but
 *   only emit `00`.
 *
 * This class is the value-shaped half — pure data, no I/O. The
 * middleware in `Http\Middleware\TraceContext` does the request-
 * scoped read/write; the egress propagation lives in
 * `Concurrency\HttpClient`.
 */
final class TraceContext
{
    private const VERSION       = '00';
    private const TRACEID_RE    = '/^[0-9a-f]{32}$/';
    private const PARENTID_RE   = '/^[0-9a-f]{16}$/';
    private const FLAGS_RE      = '/^[0-9a-f]{2}$/';
    private const TRACEID_ZERO  = '00000000000000000000000000000000';
    private const PARENTID_ZERO = '0000000000000000';

    private function __construct(
        public readonly string $traceId,
        public readonly string $parentId,
        public readonly string $flags,
    ) {}

    /**
     * Parse a `traceparent` header value. Returns null if the header
     * is missing, malformed, or carries the spec's "invalid" sentinel
     * values (all-zero trace-id or all-zero parent-id) — callers
     * generate a fresh context in that case.
     *
     * Per the W3C spec, version-aware parsers MUST accept versions
     * > 00 by reading the first 4 fields and ignoring trailing data.
     * This implementation accepts any version, validates the four
     * mandatory fields, and re-emits `00` to keep outbound traffic
     * predictable.
     */
    public static function fromHeader(string $header): ?self
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        $parts = explode('-', $header);
        if (count($parts) < 4) {
            return null;
        }
        [$version, $traceId, $parentId, $flags] = $parts;
        $version  = strtolower($version);
        $traceId  = strtolower($traceId);
        $parentId = strtolower($parentId);
        $flags    = strtolower($flags);

        // Spec: version `ff` is reserved for "invalid" — reject it.
        if ($version === 'ff' || preg_match('/^[0-9a-f]{2}$/', $version) !== 1) {
            return null;
        }
        if (preg_match(self::TRACEID_RE,  $traceId)  !== 1) { return null; }
        if (preg_match(self::PARENTID_RE, $parentId) !== 1) { return null; }
        if (preg_match(self::FLAGS_RE,    $flags)    !== 1) { return null; }
        if ($traceId === self::TRACEID_ZERO)   { return null; }
        if ($parentId === self::PARENTID_ZERO) { return null; }

        return new self($traceId, $parentId, $flags);
    }

    /**
     * Mint a fresh trace context — new random trace-id and parent-id,
     * sampled flag clear (`00`). Used when the inbound request carries
     * no `traceparent` (or one that fails validation).
     *
     * Sampling defaults to off so the middleware never makes
     * sampling decisions on behalf of an exporter that hasn't been
     * configured. Apps that wire OTel can flip the flag at the
     * sampler layer.
     */
    public static function generate(): self
    {
        return new self(
            traceId:  bin2hex(random_bytes(16)),
            parentId: bin2hex(random_bytes(8)),
            flags:    '00',
        );
    }

    /**
     * Same trace-id, fresh parent-id. Used when this server makes an
     * outbound call: the receiving service should see "I was called
     * by parent X" where X is unique per outbound call but still
     * threaded into the same trace as the inbound request.
     *
     * Flags propagate (sampled-bit decisions are taken at the trace
     * root and inherited downstream).
     */
    public function withNewParent(): self
    {
        return new self(
            traceId:  $this->traceId,
            parentId: bin2hex(random_bytes(8)),
            flags:    $this->flags,
        );
    }

    /** Format as the wire-shape `00-<trace>-<parent>-<flags>` value. */
    public function toHeader(): string
    {
        return self::VERSION . '-' . $this->traceId . '-' . $this->parentId . '-' . $this->flags;
    }

    public function isSampled(): bool
    {
        // Bit 0 of flags = sampled.
        return (hexdec($this->flags) & 0x01) === 1;
    }
}
