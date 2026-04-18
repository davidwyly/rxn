<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

/**
 * Conditional-GET support via weak ETags. After the downstream
 * pipeline returns, hash the action payload (the envelope's `data`,
 * not the whole envelope — per-request meta like elapsed_ms would
 * otherwise invalidate every cache entry) and emit it as the ETag.
 * If the client's `If-None-Match` matches, short-circuit to a 304
 * Not Modified with no body.
 *
 * Only applies to idempotent, successful reads (GET / HEAD with a
 * 200 response); everything else passes through unchanged. Matches
 * the behaviour most CDNs and gateways already expect from an
 * origin, so upstream caches + this middleware compose cleanly.
 */
final class ETag implements Middleware
{
    /** @var callable(string): void */
    private $emitHeader;
    /** @var callable(int): void */
    private $emitStatus;

    public function __construct(
        ?callable $emitHeader = null,
        ?callable $emitStatus = null
    ) {
        $this->emitHeader = $emitHeader ?? static fn (string $h) => header($h);
        $this->emitStatus = $emitStatus ?? static fn (int $c) => http_response_code($c);
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $response;
        }
        $code = $response->getCode();
        if ($code !== null && (int)$code !== 200) {
            return $response;
        }
        if ($response->data === null) {
            return $response;
        }

        $body = json_encode($response->data, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return $response;
        }
        $etag = 'W/"' . substr(sha1($body), 0, 16) . '"';
        ($this->emitHeader)('ETag: ' . $etag);

        $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($inm !== '' && self::matches($inm, $etag)) {
            ($this->emitStatus)(304);
            return Response::notModified();
        }
        return $response;
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
