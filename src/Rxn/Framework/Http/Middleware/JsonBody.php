<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Error\RequestException;

/**
 * Decodes `application/json` request bodies on POST / PUT / PATCH so
 * downstream code (controllers, the Binder) can reach them without
 * rolling their own `json_decode`.
 *
 * Enforces a size limit (default 1 MiB) before reading the stream to
 * prevent an unbounded body from exhausting memory. Invalid JSON
 * yields a 400; oversized bodies yield a 413; a mismatched
 * Content-Type on a body-bearing method yields a 415.
 *
 * Stores the decoded payload two ways for compatibility:
 *  - `$request->withParsedBody($decoded)` for PSR-7-aware downstream
 *  - merges keys into `$_POST` so the existing `Binder` (which reads
 *    `$_GET + $_POST`) sees the JSON body without any further glue
 *
 * The rest of the request (query string, headers) is untouched; this
 * middleware is strictly about the JSON body path. Non-body methods
 * (GET, HEAD, DELETE, OPTIONS) pass through unchanged.
 */
final class JsonBody implements MiddlewareInterface
{
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    private int $maxBytes;

    /**
     * @param int $maxBytes max accepted Content-Length (default 1 MiB)
     */
    public function __construct(int $maxBytes = 1048576)
    {
        $this->maxBytes = $maxBytes;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, self::BODY_METHODS, true)) {
            return $handler->handle($request);
        }

        $contentType = $this->parseContentType($request->getHeaderLine('Content-Type'));
        if ($contentType === '') {
            // No body at all — nothing to decode, nothing to validate.
            return $handler->handle($request);
        }
        if ($contentType !== 'application/json') {
            throw new RequestException(
                "Expected Content-Type: application/json, got '$contentType'",
                415
            );
        }

        $declared = (int)($request->getHeaderLine('Content-Length') ?: 0);
        if ($declared > $this->maxBytes) {
            throw new RequestException(
                "Request body exceeds maximum of {$this->maxBytes} bytes",
                413
            );
        }

        $raw = (string)$request->getBody();
        if ($raw === '') {
            return $handler->handle($request);
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

        // Belt-and-braces — PSR-7 parsedBody for new code, $_POST
        // mutation for the existing Binder which reads $_GET + $_POST.
        foreach ($decoded as $key => $value) {
            $_POST[$key] = $value;
        }
        return $handler->handle($request->withParsedBody($decoded));
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
