<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin bridge between PHP superglobals and PSR-7. Exists so apps
 * built on Rxn can opt into the PSR-15 middleware ecosystem without
 * giving up the rest of the framework.
 *
 *   $request  = PsrAdapter::serverRequestFromGlobals();
 *   $response = $pipeline->handle($request);
 *   PsrAdapter::emit($response);
 */
final class PsrAdapter
{
    /**
     * Build a PSR-7 ServerRequest from the current PHP globals.
     *
     * Bypasses Nyholm's `ServerRequestCreator::fromGlobals()` and
     * builds the request directly via Nyholm's `ServerRequest` and
     * `Uri` constructors. The PSR-7 result is identical — same
     * concrete classes, same observable behaviour — but the
     * construction path skips the ~15 immutable `with*()` clones the
     * default builder performs (one per URI part, one per header,
     * one each for cookies / query params / parsed body / uploaded
     * files).
     *
     * Only the conditional with*() calls fire: cookies when
     * $_COOKIE has entries, uploaded files when $_FILES does, and
     * parsed body only on form-content-type POSTs (matching the
     * default builder's existing skip for JSON requests).
     */
    public static function serverRequestFromGlobals(): ServerRequestInterface
    {
        $server = $_SERVER;
        $method = isset($server['REQUEST_METHOD']) ? (string)$server['REQUEST_METHOD'] : 'GET';

        // Build URI string in one pass instead of chaining
        // withScheme/withHost/withPort/withPath/withQuery on a
        // Uri('') (each clones the Uri).
        $scheme = $server['HTTP_X_FORWARDED_PROTO']
            ?? $server['REQUEST_SCHEME']
            ?? (isset($server['HTTPS']) && $server['HTTPS'] !== 'off' && $server['HTTPS'] !== '' ? 'https' : 'http');
        $host = $server['HTTP_HOST']
            ?? $server['SERVER_NAME']
            ?? 'localhost';
        $path  = isset($server['REQUEST_URI']) ? explode('?', (string)$server['REQUEST_URI'], 2)[0] : '/';
        $query = isset($server['QUERY_STRING']) ? (string)$server['QUERY_STRING'] : '';

        $uriString = $scheme . '://' . $host . $path;
        if ($query !== '') {
            $uriString .= '?' . $query;
        }

        // Headers from $_SERVER. getallheaders() is faster when
        // available (apache + cgi-fcgi); the fallback walks $_SERVER
        // once with native string ops.
        $headers = function_exists('getallheaders')
            ? getallheaders()
            : self::headersFromServer($server);

        $protocol = isset($server['SERVER_PROTOCOL'])
            ? str_replace('HTTP/', '', (string)$server['SERVER_PROTOCOL'])
            : '1.1';

        // ServerRequest's constructor sets method, uri, headers,
        // queryParams (via parse_str on uri.query), protocol, and
        // serverParams. Body wraps php://input — Nyholm's default
        // ServerRequestCreator::fromGlobals does the same — so
        // request bodies are actually visible to anything that
        // calls getBody(). Without this, Nyholm's MessageTrait
        // initialises the stream to an empty in-memory string on
        // first read, which silently strips POST / PUT / PATCH
        // payloads — the JSON body never reaches a downstream
        // middleware or handler.
        //
        // fopen('php://input') itself is a cheap descriptor
        // allocation; the actual read cost lives in
        // getBody()->getContents() and is paid only when (and if)
        // something asks for it.
        $body = \fopen('php://input', 'r') ?: null;
        $request = new ServerRequest(
            $method,
            new Uri($uriString),
            $headers,
            $body,
            $protocol,
            $server,
        );

        if ($_COOKIE !== []) {
            $request = $request->withCookieParams($_COOKIE);
        }

        if ($method === 'POST') {
            // Match the default builder's parsed-body rule: only set
            // when the Content-Type is form-encoded; JSON / other
            // bodies stay raw and middleware can decode them.
            $contentType = self::headerValue($headers, 'content-type');
            if ($contentType !== null) {
                $primary = strtolower(trim(explode(';', $contentType, 2)[0]));
                if ($primary === 'application/x-www-form-urlencoded' || $primary === 'multipart/form-data') {
                    $request = $request->withParsedBody($_POST);
                }
            }
        }

        if ($_FILES !== []) {
            $request = $request->withUploadedFiles(self::normalizeFiles($_FILES));
        }

        return $request;
    }

    /**
     * Extract HTTP-* and CONTENT_* keys from a $_SERVER-shaped array
     * into a lowercased name => value array. Used as a fallback when
     * `getallheaders()` is unavailable.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtr(strtolower(substr($key, 5)), '_', '-');
                $headers[$name] = (string)$value;
                continue;
            }
            if (str_starts_with($key, 'CONTENT_')) {
                $headers['content-' . strtolower(substr($key, 8))] = (string)$value;
            }
        }
        return $headers;
    }

    /**
     * Case-insensitive header lookup.
     *
     * @param array<string, string> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string)$k) === $needle) {
                return (string)$v;
            }
        }
        return null;
    }

    /**
     * Convert $_FILES into PSR-7 UploadedFile instances. Mirrors the
     * shape Nyholm's ServerRequestCreator produces — single-file vs
     * nested file specs, same key naming.
     *
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private static function normalizeFiles(array $files): array
    {
        $factory = self::factory();
        $out = [];
        foreach ($files as $key => $value) {
            if ($value instanceof \Psr\Http\Message\UploadedFileInterface) {
                $out[$key] = $value;
            } elseif (is_array($value) && isset($value['tmp_name'])) {
                $out[$key] = self::createUploadedFile($factory, $value);
            } elseif (is_array($value)) {
                $out[$key] = self::normalizeFiles($value);
            }
        }
        return $out;
    }

    private static function createUploadedFile(Psr17Factory $factory, array $spec): \Psr\Http\Message\UploadedFileInterface|array
    {
        if (is_array($spec['tmp_name'])) {
            $out = [];
            foreach (array_keys($spec['tmp_name']) as $k) {
                $out[$k] = self::createUploadedFile($factory, [
                    'tmp_name' => $spec['tmp_name'][$k],
                    'size'     => $spec['size'][$k]  ?? 0,
                    'error'    => $spec['error'][$k] ?? UPLOAD_ERR_OK,
                    'name'     => $spec['name'][$k]  ?? null,
                    'type'     => $spec['type'][$k]  ?? null,
                ]);
            }
            return $out;
        }
        $stream = ($spec['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK
            ? $factory->createStream()
            : (function () use ($factory, $spec) {
                try {
                    return $factory->createStreamFromFile($spec['tmp_name']);
                } catch (\RuntimeException) {
                    return $factory->createStream();
                }
            })();
        return $factory->createUploadedFile(
            $stream,
            (int)($spec['size'] ?? 0),
            (int)($spec['error'] ?? UPLOAD_ERR_OK),
            $spec['name'] ?? null,
            $spec['type'] ?? null,
        );
    }

    /**
     * Emit a PSR-7 Response to the current SAPI: status line,
     * headers, then body. Safe to call from a standard php-fpm
     * worker.
     */
    public static function emit(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Headers already sent at $file:$line");
        }
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            // Replace any previously-set header with the same name.
            $first = true;
            foreach ($values as $value) {
                header($name . ': ' . $value, $first);
                $first = false;
            }
        }
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(8192);
        }
    }

    /**
     * Convenience factory returning Nyholm's PSR-17 factory, which
     * implements every PSR-17 interface (RequestFactory,
     * ResponseFactory, StreamFactory, UploadedFileFactory,
     * UriFactory, ServerRequestFactory).
     */
    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }
}
