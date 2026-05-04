<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Versioning;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Per-route response decorator that adds RFC 8594 deprecation
 * signals to outgoing responses. Used by the `Scanner` when a
 * `#[Version]` attribute carries `deprecatedAt` / `sunsetAt`,
 * but apps can also wire it directly:
 *
 *   $router->get('/products', $handler)
 *       ->middleware(new Deprecation('2026-01-01', '2026-12-31'));
 *
 * Headers emitted:
 *
 *   - `Deprecation: <IMF-fixdate>` when a deprecation date is
 *     set. Per RFC 8594 §2 the value is "the deprecation date
 *     of the resource version" formatted as
 *     `Sun, 06 Nov 1994 08:49:37 GMT`.
 *   - `Sunset: <IMF-fixdate>` when a sunset date is set
 *     (RFC 8594 §3).
 *
 * Both are advisory headers — they don't change response status
 * or behaviour, just signal to API clients (and intermediaries
 * like API gateways) that the endpoint is on its way out.
 */
final class Deprecation implements MiddlewareInterface
{
    public function __construct(
        private readonly ?string $deprecatedAt = null,
        private readonly ?string $sunsetAt = null,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);
        if ($this->deprecatedAt !== null) {
            $formatted = self::toImfFixdate($this->deprecatedAt);
            if ($formatted !== null) {
                $response = $response->withHeader('Deprecation', $formatted);
            }
        }
        if ($this->sunsetAt !== null) {
            $formatted = self::toImfFixdate($this->sunsetAt);
            if ($formatted !== null) {
                $response = $response->withHeader('Sunset', $formatted);
            }
        }
        return $response;
    }

    /**
     * Turn an ISO 8601-ish input into an RFC 7231 IMF-fixdate
     * (`Sun, 06 Nov 1994 08:49:37 GMT`). Inputs the framework
     * accepts:
     *
     *   - bare date `'2026-01-01'` → midnight UTC
     *   - full ISO `'2026-01-01T12:00:00+00:00'`
     *   - any string `\DateTimeImmutable::__construct` accepts
     *
     * Returns null on parse failure rather than throwing — the
     * decorator's contract is "best-effort header signalling",
     * not "fail the request."
     */
    private static function toImfFixdate(string $input): ?string
    {
        if (trim($input) === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($input, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        // RFC 7231 §7.1.1.1 IMF-fixdate format. UTC required.
        return $dt->setTimezone(new \DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s') . ' GMT';
    }
}
