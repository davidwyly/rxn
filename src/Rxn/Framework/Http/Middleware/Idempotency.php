<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Idempotency\Event\IdempotencyHit;
use Rxn\Framework\Http\Idempotency\Event\IdempotencyMiss;
use Rxn\Framework\Http\Idempotency\IdempotencyStore;
use Rxn\Framework\Http\Idempotency\StoredResponse;

/**
 * Stripe-style idempotency middleware.
 *
 *   POST /payments  Idempotency-Key: <uuid>
 *
 * For mutating requests that carry the configured header
 * (`Idempotency-Key` by default), the middleware stores the
 * response keyed by the header value plus a fingerprint of the
 * request body. Subsequent retries with the same key replay the
 * stored response with `Idempotent-Replayed: true`.
 *
 * Five paths through the middleware:
 *
 *  1. **No header** — pass through; the middleware does nothing.
 *  2. **Cold key** — process the request, store the response,
 *     return.
 *  3. **Replay (matching fingerprint)** — return the stored
 *     response with `Idempotent-Replayed: true`.
 *  4. **Replay with mismatched fingerprint** — same key but a
 *     different request body. Return 400 Problem Details:
 *     `idempotency_key_in_use_with_different_body`.
 *  5. **Concurrent retry while original in-flight** — the lock is
 *     held; return 409 Conflict.
 *
 * Per Stripe's pattern, the middleware applies to mutating verbs
 * only by default (POST / PUT / PATCH / DELETE). GETs are
 * idempotent by definition; replaying them is wasted bytes.
 *
 *   $idempotency = new Idempotency(
 *       new FileIdempotencyStore('/var/run/rxn/idempotency'),
 *   );
 *
 *   $pipeline = (new Pipeline())->add($idempotency);
 *
 * Apps that already run a PSR-16-shaped cache (Redis / Memcached
 * / APCu) wire it up via `Psr16IdempotencyStore` — see that
 * class's docblock for the zero-dependency story.
 */
final class Idempotency implements MiddlewareInterface
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly string $headerName     = 'Idempotency-Key',
        private readonly int    $ttlSeconds     = 86_400,
        private readonly int    $lockTtlSeconds = 30,
        /** @var list<string> */
        private readonly array  $methods        = ['POST', 'PUT', 'PATCH', 'DELETE'],
        private readonly int    $maxBodyBytes   = 1_048_576,
        /** @var list<string> */
        private readonly array  $replayHeaderAllowlist = ['Content-Type'],
        // Optional PSR-14 dispatcher. When provided, the middleware
        // emits `IdempotencyHit` on replay and `IdempotencyMiss`
        // when the cold path runs. Null → no allocation, no dispatch.
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, $this->methods, true)) {
            return $handler->handle($request);
        }
        $key = $this->incomingKey($request);
        if ($key === null) {
            return $handler->handle($request);
        }

        $scopedKey   = $this->scopedKey($key, $request);
        $fingerprint = $this->fingerprint($method, $request);
        if ($fingerprint === null) {
            return self::renderProblem(
                413,
                'idempotency_request_body_too_large',
                'Request body exceeds idempotency fingerprint size limit.',
            );
        }

        // Replay path — cache hit.
        $stored = $this->store->get($scopedKey);
        if ($stored !== null) {
            if ($stored->fingerprint !== $fingerprint) {
                // Same key, different request shape → client bug.
                return self::renderProblem(
                    400,
                    'idempotency_key_in_use_with_different_body',
                    'Idempotency-Key was reused with a different request body.',
                );
            }
            $this->events?->dispatch(new IdempotencyHit($key, $stored->statusCode, $fingerprint));
            return $this->renderStored($stored);
        }

        $this->events?->dispatch(new IdempotencyMiss($key, $fingerprint));

        // Cold path — acquire lock, process, store.
        if (!$this->store->lock($scopedKey, $this->lockTtlSeconds)) {
            return self::renderProblem(
                409,
                'idempotency_key_in_use',
                'A request with this Idempotency-Key is already being processed.',
            );
        }

        try {
            $response = $handler->handle($request);
            // Only persist successful + 4xx responses (including
            // validation failures — clients retrying a 422 should
            // get the same 422 back). Skip 5xx so transient server
            // errors don't get cached as the canonical answer.
            if ($response->getStatusCode() < 500) {
                $body = (string)$response->getBody();
                if ($response->getBody()->isSeekable()) {
                    $response->getBody()->rewind();
                }
                $this->store->put(
                    $scopedKey,
                    new StoredResponse(
                        statusCode:  $response->getStatusCode(),
                        headers:     $this->filterReplayHeaders($response->getHeaders()),
                        body:        $body,
                        fingerprint: $fingerprint,
                        createdAt:   time(),
                    ),
                    $this->ttlSeconds,
                );
            }
            return $response;
        } finally {
            $this->store->release($scopedKey);
        }
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private function filterReplayHeaders(array $headers): array
    {
        // Build a lowercase-keyed index so allowlist matching is
        // case-insensitive, mirroring PSR-7's case-insensitivity guarantee.
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = $values;
        }

        $filtered = [];
        foreach ($this->replayHeaderAllowlist as $header) {
            $lower = strtolower($header);
            if (!isset($normalized[$lower])) {
                continue;
            }
            // Store under lowercase keys to match PSR-7 normalisation
            // conventions (see StoredResponse docblock).
            $filtered[$lower] = $normalized[$lower];
        }
        return $filtered;
    }

    private function incomingKey(ServerRequestInterface $request): ?string
    {
        $value = $request->getHeaderLine($this->headerName);
        if ($value === '') {
            return null;
        }
        // Stripe's recommendation: cap at 255 chars to bound storage
        // and prevent abuse via gigantic keys. UUIDs are 36, ULIDs
        // 26 — a reasonable cap.
        if (strlen($value) > 255) {
            return null;
        }
        return $value;
    }

    /**
     * Stable hash of the request shape used to detect "same key,
     * different body" client bugs. Inputs: HTTP method + URI path
     * + raw request body. Query params are part of the URI and so
     * captured automatically.
     */
    private function fingerprint(string $method, ServerRequestInterface $request): ?string
    {
        $uri   = (string)$request->getUri();
        $scope = $request->getHeaderLine('Authorization');
        $body  = (string)$request->getBody();
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }
        if (strlen($body) > $this->maxBodyBytes) {
            return null;
        }
        return hash('sha256', $scope . "\n" . $method . "\n" . $uri . "\n" . $body);
    }

    private function scopedKey(string $incomingKey, ServerRequestInterface $request): string
    {
        $scope = $request->getHeaderLine('Authorization');
        return hash('sha256', $scope . "\n" . $incomingKey);
    }

    private function renderStored(StoredResponse $stored): ResponseInterface
    {
        // Re-apply the allowlist filter so that any StoredResponse
        // persisted before this change (which may still contain
        // unfiltered headers) does not replay sensitive headers.
        $response = new Psr7Response(
            $stored->statusCode,
            $this->filterReplayHeaders($stored->headers),
            $stored->body,
        );
        return $response->withHeader('Idempotent-Replayed', 'true');
    }

    private static function renderProblem(int $status, string $type, string $detail): ResponseInterface
    {
        $body = json_encode([
            'type'   => $type,
            'title'  => $detail,
            'status' => $status,
            'detail' => $detail,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new Psr7Response(
            $status,
            ['Content-Type' => 'application/problem+json'],
            $body,
        );
    }
}
