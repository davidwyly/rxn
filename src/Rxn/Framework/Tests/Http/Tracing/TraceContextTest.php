<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Tracing;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Tracing\TraceContext;

/**
 * Unit tests for the W3C Trace Context value object. Each test
 * isolates one rule from the spec — parsing valid input, rejecting
 * each kind of malformed input, the spec's "invalid" sentinels,
 * version forward-compat, and the round-trip property of
 * `fromHeader(toHeader())`.
 */
final class TraceContextTest extends TestCase
{
    private const VALID = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function testParsesValidHeader(): void
    {
        $ctx = TraceContext::fromHeader(self::VALID);
        $this->assertNotNull($ctx);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $ctx->traceId);
        $this->assertSame('00f067aa0ba902b7', $ctx->parentId);
        $this->assertSame('01', $ctx->flags);
        $this->assertTrue($ctx->isSampled());
    }

    public function testRejectsMissingHeader(): void
    {
        $this->assertNull(TraceContext::fromHeader(''));
        $this->assertNull(TraceContext::fromHeader('   '));
    }

    public function testRejectsMalformedFormat(): void
    {
        $this->assertNull(TraceContext::fromHeader('not-a-traceparent'));
        $this->assertNull(TraceContext::fromHeader('00-tooshort-00f067aa0ba902b7-01'));
        $this->assertNull(TraceContext::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-tooshort-01'));
        $this->assertNull(TraceContext::fromHeader('zz-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'));
    }

    public function testRejectsAllZeroTraceId(): void
    {
        // W3C spec: all-zero trace-id is the "invalid" sentinel.
        $invalid = '00-00000000000000000000000000000000-00f067aa0ba902b7-01';
        $this->assertNull(TraceContext::fromHeader($invalid));
    }

    public function testRejectsAllZeroParentId(): void
    {
        $invalid = '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01';
        $this->assertNull(TraceContext::fromHeader($invalid));
    }

    public function testRejectsReservedVersionFf(): void
    {
        // Per spec, `ff` is reserved as the "invalid" version marker.
        $invalid = 'ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $this->assertNull(TraceContext::fromHeader($invalid));
    }

    public function testAcceptsHigherVersionsForForwardCompat(): void
    {
        // Spec: parsers MUST accept versions > 00 by reading the
        // first four fields. We re-emit `00` on the way out, but
        // we don't reject the input.
        $futureVersion = '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $ctx = TraceContext::fromHeader($futureVersion);
        $this->assertNotNull($ctx);
        // Output version is normalised to `00`.
        $this->assertStringStartsWith('00-', $ctx->toHeader());
    }

    public function testIgnoresTrailingFieldsOnHigherVersions(): void
    {
        // Versions after `00` may add fields. Parsers MUST ignore
        // them. Validates that count>=4 (not count==4 strictly).
        $extended = '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-someextra';
        $this->assertNotNull(TraceContext::fromHeader($extended));
    }

    public function testRejectsExtraFieldsOnVersionZeroZero(): void
    {
        // W3C spec: version `00` MUST be exactly 4 fields. Trailing
        // segments are only allowed on versions > 00 (forward-compat
        // for future field additions). Accepting trailing fields on
        // `00` would let us continue traces fully-compliant peers
        // correctly drop, creating inconsistent propagation.
        $invalid = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-someextra';
        $this->assertNull(TraceContext::fromHeader($invalid));
    }

    public function testNormalisesCaseToLower(): void
    {
        // Spec: hex MUST be lowercase on the wire, but parsers
        // accept uppercase as a forgiveness measure.
        $upper = '00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-01';
        $ctx = TraceContext::fromHeader($upper);
        $this->assertNotNull($ctx);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $ctx->traceId);
    }

    public function testRoundTripsThroughHeader(): void
    {
        $ctx = TraceContext::fromHeader(self::VALID);
        $this->assertNotNull($ctx);
        $this->assertSame(self::VALID, $ctx->toHeader());
    }

    public function testGenerateProducesValidContext(): void
    {
        $ctx = TraceContext::generate();
        // Round-trip through fromHeader proves all-format-rules-pass.
        $reparsed = TraceContext::fromHeader($ctx->toHeader());
        $this->assertNotNull($reparsed);
        $this->assertSame($ctx->traceId, $reparsed->traceId);
        $this->assertSame($ctx->parentId, $reparsed->parentId);
        // Default flags = 00 (not sampled — the framework doesn't
        // make sampling decisions on behalf of unconfigured exporters).
        $this->assertSame('00', $ctx->flags);
        $this->assertFalse($ctx->isSampled());
    }

    public function testGenerateProducesUniqueIds(): void
    {
        $a = TraceContext::generate();
        $b = TraceContext::generate();
        $this->assertNotSame($a->traceId, $b->traceId);
        $this->assertNotSame($a->parentId, $b->parentId);
    }

    public function testWithNewParentKeepsTraceIdAndFlags(): void
    {
        $ctx = TraceContext::fromHeader(self::VALID);
        $next = $ctx->withNewParent();
        $this->assertSame($ctx->traceId, $next->traceId);
        $this->assertSame($ctx->flags, $next->flags);
        $this->assertNotSame($ctx->parentId, $next->parentId);
        // New parent-id has the right shape.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $next->parentId);
    }

    public function testIsSampledHonoursLowestBit(): void
    {
        $sampled = TraceContext::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        $notSampled = TraceContext::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');
        // Higher bits set but bit 0 clear → not sampled.
        $reservedHi = TraceContext::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-fe');

        $this->assertTrue($sampled?->isSampled());
        $this->assertFalse($notSampled?->isSampled());
        $this->assertFalse($reservedHi?->isSampled());
    }
}
