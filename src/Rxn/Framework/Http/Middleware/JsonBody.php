<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Rxn\Framework\Error\RequestException;
use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

/**
 * Decodes `application/json` request bodies on POST / PUT / PATCH so
 * controllers can reach them through `$collector->getFromPost()`
 * without rolling their own `json_decode`.
 *
 * Enforces a size limit (default 1 MiB) before reading the stream to
 * prevent an unbounded body from exhausting memory. Invalid JSON
 * yields a 400; oversized bodies yield a 413; a mismatched
 * Content-Type on a body-bearing method yields a 415.
 *
 * The rest of the request (query string, headers) is untouched; this
 * middleware is strictly about the JSON body path. Non-body methods
 * (GET, HEAD, DELETE, OPTIONS) pass through unchanged.
 */
final class JsonBody implements Middleware
{
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    private int $maxBytes;
    /** @var callable(): string */
    private $readBody;

    /**
     * @param int        $maxBytes max accepted Content-Length (default 1 MiB)
     * @param ?callable  $readBody injected body reader for tests
     *                             (default reads `php://input`)
     */
    public function __construct(int $maxBytes = 1048576, ?callable $readBody = null)
    {
        $this->maxBytes = $maxBytes;
        $this->readBody = $readBody ?? static fn (): string => (string)file_get_contents('php://input');
    }

    public function handle(Request $request, callable $next): Response
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, self::BODY_METHODS, true)) {
            return $next($request);
        }

        $contentType = $this->parseContentType($_SERVER['CONTENT_TYPE'] ?? '');
        if ($contentType === '') {
            // No body at all — nothing to decode, nothing to validate.
            return $next($request);
        }
        if ($contentType !== 'application/json') {
            throw new RequestException(
                "Expected Content-Type: application/json, got '$contentType'",
                415
            );
        }

        $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($declared > $this->maxBytes) {
            throw new RequestException(
                "Request body exceeds maximum of {$this->maxBytes} bytes",
                413
            );
        }

        $raw = ($this->readBody)();
        if ($raw === '') {
            return $next($request);
        }
        if (strlen($raw) > $this->maxBytes) {
            throw new RequestException(
                "Request body exceeds maximum of {$this->maxBytes} bytes",
                413
            );
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RequestException(
                'Invalid JSON body: ' . json_last_error_msg(),
                400
            );
        }
        if (!is_array($decoded)) {
            throw new RequestException('JSON body must be an object or array', 400);
        }

        foreach ($decoded as $key => $value) {
            $_POST[$key] = $value;
        }
        return $next($request);
    }

    private function parseContentType(string $header): string
    {
        if ($header === '') {
            return '';
        }
        $semi = strpos($header, ';');
        $type = $semi === false ? $header : substr($header, 0, $semi);
        return strtolower(trim($type));
    }
}
