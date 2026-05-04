<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Versioning;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Versioning\Deprecation;

/**
 * Unit tests for the Deprecation middleware. Three contracts on
 * trial:
 *
 *   1. Bare ISO date inputs (`'2026-01-01'`) parse as midnight UTC
 *      and serialize as RFC 7231 IMF-fixdate.
 *   2. Full ISO 8601 inputs (`'2026-01-01T12:00:00Z'`) round-trip
 *      to the right IMF-fixdate.
 *   3. Null args + unparseable inputs are silently dropped — the
 *      middleware never fails the request just because a
 *      deprecation date couldn't be formatted.
 */
final class DeprecationTest extends TestCase
{
    private function terminal(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    private function exercise(Deprecation $mw): ResponseInterface
    {
        return $mw->process(
            new ServerRequest('GET', 'http://example.test/'),
            $this->terminal(),
        );
    }

    public function testBareIsoDateBecomesImfFixdate(): void
    {
        $response = $this->exercise(new Deprecation('2026-01-01', '2026-12-31'));
        $this->assertSame('Thu, 01 Jan 2026 00:00:00 GMT', $response->getHeaderLine('Deprecation'));
        $this->assertSame('Thu, 31 Dec 2026 00:00:00 GMT', $response->getHeaderLine('Sunset'));
    }

    public function testFullIsoDateRoundTripsToImfFixdate(): void
    {
        $response = $this->exercise(new Deprecation('2026-01-01T12:34:56+00:00', null));
        $this->assertSame('Thu, 01 Jan 2026 12:34:56 GMT', $response->getHeaderLine('Deprecation'));
        $this->assertSame('', $response->getHeaderLine('Sunset'));
    }

    public function testTimezonedDateConvertsToUtc(): void
    {
        // 2026-01-01 00:00:00 in +05:00 = 2025-12-31 19:00:00 UTC.
        // The middleware emits the UTC equivalent.
        $response = $this->exercise(new Deprecation('2026-01-01T00:00:00+05:00', null));
        $this->assertSame('Wed, 31 Dec 2025 19:00:00 GMT', $response->getHeaderLine('Deprecation'));
    }

    public function testNullArgsEmitNoHeaders(): void
    {
        $response = $this->exercise(new Deprecation(null, null));
        $this->assertSame('', $response->getHeaderLine('Deprecation'));
        $this->assertSame('', $response->getHeaderLine('Sunset'));
    }

    public function testUnparseableDateEmitsNothingNotAFailure(): void
    {
        // Garbage date — the middleware silently drops it rather
        // than 500ing the request. The contract is "best-effort
        // header signalling", not "die on bad config."
        $response = $this->exercise(new Deprecation('not-a-date', 'also-not'));
        $this->assertSame('', $response->getHeaderLine('Deprecation'));
        $this->assertSame('', $response->getHeaderLine('Sunset'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeprecationOnlyDoesNotAddSunset(): void
    {
        // Common case: "this version is deprecated, but we
        // haven't decided when it'll be retired." Deprecation
        // header fires, Sunset is left blank.
        $response = $this->exercise(new Deprecation('2026-01-01', null));
        $this->assertNotSame('', $response->getHeaderLine('Deprecation'));
        $this->assertSame('', $response->getHeaderLine('Sunset'));
    }

    public function testSunsetOnlyDoesNotAddDeprecation(): void
    {
        // Less common, but valid per RFC 8594: an endpoint can be
        // scheduled for sunset without a separate deprecation
        // signal (the sunset itself implies the upcoming removal).
        $response = $this->exercise(new Deprecation(null, '2026-12-31'));
        $this->assertSame('', $response->getHeaderLine('Deprecation'));
        $this->assertNotSame('', $response->getHeaderLine('Sunset'));
    }

    public function testTerminalResponseIsPreserved(): void
    {
        // The middleware must NOT swallow the downstream
        // response — only add headers to it. Body, status,
        // existing headers all pass through.
        $upstream = new Deprecation('2026-01-01', null);
        $response = $upstream->process(
            new ServerRequest('GET', 'http://example.test/'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(
                        201,
                        ['Content-Type' => 'application/json', 'X-Custom' => 'preserved'],
                        '{"id":42}',
                    );
                }
            },
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('preserved', $response->getHeaderLine('X-Custom'));
        $this->assertSame('{"id":42}', (string) $response->getBody());
    }
}
