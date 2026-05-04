<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stateless Bearer-token authentication. Reads the
 * `Authorization: Bearer <token>` header, hands the token to a
 * caller-supplied resolver, and either:
 *
 *  - 401 Problem Details on missing / malformed / unrecognised
 *    token, or
 *  - delegates to the next handler and exposes the resolved
 *    principal via `BearerAuth::current()` for downstream code.
 *
 *   $resolver = fn (string $token) => $userRepo->findByToken($token);
 *   $pipeline->add(new BearerAuth($resolver));
 *
 *   // inside a controller:
 *   $user = BearerAuth::current();
 *
 * The resolver returns the authenticated principal (any
 * `array<string, mixed>` shape — typically a user record) or
 * `null` to reject the request. Apps that want token introspection,
 * cache hits, JWT validation, etc. wrap that in the resolver
 * closure — the middleware doesn't care.
 */
final class BearerAuth implements MiddlewareInterface
{
    /** @var array<string, mixed>|null */
    private static ?array $current = null;

    /** @var callable(string): (array<string, mixed>|null) */
    private $resolver;

    /**
     * `callable` is type-hinted on the parameter so a non-callable
     * `$resolver` fails fast at construction with a TypeError, not
     * later at the first request. Storing on an untyped property
     * (vs. `\Closure`) is intentional: callers can pass
     * `[$obj, 'method']` arrays, `Class::method` strings, invokable
     * objects — anything `is_callable($x)` accepts — without
     * wrapping in `Closure::fromCallable()`.
     *
     * @param callable(string): (array<string, mixed>|null) $resolver
     */
    public function __construct(
        callable $resolver,
        private readonly string $headerName = 'Authorization',
    ) {
        $this->resolver = $resolver;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $header = $request->getHeaderLine($this->headerName);
        $token  = self::extractBearer($header);
        if ($token === null) {
            return self::renderUnauthorized();
        }
        $principal = ($this->resolver)($token);
        if (!is_array($principal) || $principal === []) {
            return self::renderUnauthorized();
        }
        self::$current = $principal;
        try {
            return $handler->handle($request);
        } finally {
            self::$current = null;
        }
    }

    /**
     * Returns the authenticated principal for the current request,
     * or null when no `BearerAuth` is on the pipeline (or the
     * request hasn't reached it yet). Cleared in `process()`'s
     * `finally` so a long-lived worker doesn't leak the previous
     * request's principal into the next.
     *
     * Sync-only by design: this static is process-wide and is only
     * safe when requests are handled one-at-a-time (the framework's
     * intended PHP-FPM model). Coroutine runtimes (Swoole, fibers)
     * need an alternative principal-propagation mechanism.
     *
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * Pull the token out of an `Authorization: Bearer <token>`
     * header value. Case-insensitive on the scheme; tolerates one
     * or more whitespace characters between scheme and token, and
     * optional trailing whitespace — same flexibility most HTTP
     * stacks accept on the `Authorization` header.
     */
    private static function extractBearer(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        if (!preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return null;
        }
        return $matches[1];
    }

    private static function renderUnauthorized(): ResponseInterface
    {
        // RFC 7807 Problem Details — same shape every other failure
        // path emits, including the framework's exception handler.
        $body = json_encode([
            'type'   => 'about:blank',
            'title'  => 'Unauthorized',
            'status' => 401,
            'detail' => 'Authentication required',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new Psr7Response(
            401,
            ['Content-Type' => 'application/problem+json'],
            $body,
        );
    }
}
