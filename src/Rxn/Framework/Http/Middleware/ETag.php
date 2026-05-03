<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Conditional-GET support via weak ETags. After the downstream
 * handler returns, hash the response body and emit it as the ETag.
 * If the client's `If-None-Match` matches, short-circuit to a
 * 304 Not Modified with no body.
 *
 * Only applies to idempotent, successful reads (GET / HEAD with a
 * 200 response); everything else passes through unchanged. Matches
 * the behaviour most CDNs and gateways already expect from an
 * origin, so upstream caches + this middleware compose cleanly.
 *
 * Hashes the body bytes directly — under the JSON envelope shape
 * the framework emits, that includes the per-request `meta` block
 * (`elapsed_ms`, etc.). Callers that care should reset that block
 * via a downstream middleware before ETag, or set
 * `If-None-Match: *` semantics elsewhere.
 */
final class ETag implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);

        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $response;
        }
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $body = (string)$response->getBody();
        if ($body === '') {
            return $response;
        }
        $etag = 'W/"' . substr(sha1($body), 0, 16) . '"';

        $inm = $request->getHeaderLine('If-None-Match');
        if ($inm !== '' && self::matches($inm, $etag)) {
            return new Psr7Response(304, ['ETag' => $etag]);
        }
        return $response->withHeader('ETag', $etag);
    }

    /**
     * Accept a comma-separated list plus the special `*` token.
     * Match is tag-for-tag equality after trimming; weak/strong
     * markers are ignored (weak compare — RFC 7232 §2.3.2).
     */
    private static function matches(string $header, string $etag): bool
    {
        $needle = self::stripWeak($etag);
        foreach (explode(',', $header) as $candidate) {
            $c = trim($candidate);
            if ($c === '*') {
                return true;
            }
            if (self::stripWeak($c) === $needle) {
                return true;
            }
        }
        return false;
    }

    private static function stripWeak(string $tag): string
    {
        return preg_replace('/^W\//', '', trim($tag)) ?? $tag;
    }
}
