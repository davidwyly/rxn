<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

/**
 * Assigns every request a correlation id — either honouring an
 * incoming `X-Request-ID` header (when it looks sane) or minting a
 * fresh UUIDv4. The id is echoed back on the response so upstream
 * infrastructure and clients can stitch logs together.
 *
 * Downstream code (Logger, controllers) can read the current id via
 * `RequestId::current()`; null means the middleware never ran.
 */
final class RequestId implements Middleware
{
    private static ?string $current = null;

    /** @var callable(string): void */
    private $emitHeader;

    public function __construct(?callable $emitHeader = null)
    {
        $this->emitHeader = $emitHeader ?? static fn (string $h) => header($h);
    }

    public function handle(Request $request, callable $next): Response
    {
        self::$current = $this->resolveIncomingId() ?? self::generateUuid();
        ($this->emitHeader)('X-Request-ID: ' . self::$current);
        return $next($request);
    }

    public static function current(): ?string
    {
        return self::$current;
    }

    /** Expose the generator for callers that want ids outside a request. */
    public static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }

    private function resolveIncomingId(): ?string
    {
        $raw = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        if ($raw === '' || !is_string($raw)) {
            return null;
        }
        // Accept 8..128 chars of URL-safe content. Reject anything
        // weirder so a header can't smuggle CRLF or control bytes
        // into the response.
        if (!preg_match('/^[A-Za-z0-9._\-]{8,128}$/', $raw)) {
            return null;
        }
        return $raw;
    }
}
