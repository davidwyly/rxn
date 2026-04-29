<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Rxn\Framework\Http\Idempotency\IdempotencyStore;
use Rxn\Framework\Http\Idempotency\StoredResponse;
use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

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
final class Idempotency implements Middleware
{
    /** @var callable(string): void */
    private $emitHeader;

    /** @var callable(): string Read the raw request body (for fingerprinting) */
    private $readBody;

    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly string $headerName     = 'Idempotency-Key',
        private readonly int    $ttlSeconds     = 86_400,
        private readonly int    $lockTtlSeconds = 30,
        /** @var list<string> */
        private readonly array  $methods        = ['POST', 'PUT', 'PATCH', 'DELETE'],
        ?callable $emitHeader = null,
        ?callable $readBody   = null,
    ) {
        $this->emitHeader = $emitHeader ?? static fn (string $h) => header($h);
        $this->readBody   = $readBody   ?? static fn (): string => (string)file_get_contents('php://input');
    }

    public function handle(Request $request, callable $next): Response
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, $this->methods, true)) {
            return $next($request);
        }
        $key = $this->incomingKey();
        if ($key === null) {
            return $next($request);
        }

        $fingerprint = $this->fingerprint($method);

        // Replay path — cache hit.
        $stored = $this->store->get($key);
        if ($stored !== null) {
            if ($stored->fingerprint !== $fingerprint) {
                // Same key, different request shape → client bug.
                return $this->renderProblem(
                    400,
                    'idempotency_key_in_use_with_different_body',
                    'Idempotency-Key was reused with a different request body.',
                );
            }
            ($this->emitHeader)('Idempotent-Replayed: true');
            return $this->renderStored($stored, $request);
        }

        // Cold path — acquire lock, process, store.
        if (!$this->store->lock($key, $this->lockTtlSeconds)) {
            return $this->renderProblem(
                409,
                'idempotency_key_in_use',
                'A request with this Idempotency-Key is already being processed.',
            );
        }

        try {
            $response = $next($request);
            // Only persist successful + 4xx responses (including
            // validation failures — clients retrying a 422 should
            // get the same 422 back). Skip 5xx so transient server
            // errors don't get cached as the canonical answer.
            if ($response->getCode() < 500) {
                $this->store->put(
                    $key,
                    new StoredResponse(
                        statusCode:  $response->getCode(),
                        body:        $response->stripEmptyParams(),
                        fingerprint: $fingerprint,
                        createdAt:   time(),
                    ),
                    $this->ttlSeconds,
                );
            }
            return $response;
        } finally {
            $this->store->release($key);
        }
    }

    /**
     * The configured header name, normalised across PHP's various
     * accessor casings (Idempotency-Key, IDEMPOTENCY-KEY,
     * idempotency-key all map to the same logical header). PHP
     * exposes inbound headers as $_SERVER['HTTP_<UPPER_UNDERSCORED>'].
     */
    private function incomingKey(): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(strtr($this->headerName, '-', '_'));
        $value = $_SERVER[$serverKey] ?? null;
        if (!is_string($value) || $value === '') {
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
    private function fingerprint(string $method): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $body = ($this->readBody)();
        return hash('sha256', $method . "\n" . $uri . "\n" . $body);
    }

    private function renderStored(StoredResponse $stored, Request $request): Response
    {
        // Reconstruct without invoking the (heavyweight) Response
        // constructor — same trick `Response::notModified()` uses
        // internally.
        $r = (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
        $r->setRendered(false);
        $r->meta              = $stored->body['meta']              ?? null;
        $r->data              = $stored->body['data']              ?? null;
        $r->errors            = $stored->body['errors']            ?? null;
        $r->validation_errors = $stored->body['validation_errors'] ?? null;
        $r->request           = $request;
        // `code` is private on Response; the public accessor is
        // getCode(). The renderer reads it through the same public
        // surface, so set it via reflection on our local instance —
        // this is consistent with how `notModified()` does it.
        $codeProp = (new \ReflectionClass(Response::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($r, $stored->statusCode);
        return $r;
    }

    private function renderProblem(int $status, string $type, string $detail): Response
    {
        $r = (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
        $r->setRendered(false);
        $r->meta = [
            'type'   => $type,
            'title'  => $detail,
            'status' => $status,
        ];
        $codeProp = (new \ReflectionClass(Response::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($r, $status);
        return $r;
    }
}
