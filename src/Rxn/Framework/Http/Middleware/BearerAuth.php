<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Service\Auth;

/**
 * Stateless Bearer-token authentication. Reads the
 * `Authorization: Bearer <token>` header, hands the token to the
 * configured `Auth` resolver, and either:
 *
 *  - 401 Problem Details on missing / malformed / unrecognised
 *    token, or
 *  - delegates to the next handler and exposes the resolved
 *    principal via `BearerAuth::current()` for downstream code.
 *
 *   $auth->setResolver(fn (string $t) => $userRepo->findByToken($t));
 *   $pipeline->add(new BearerAuth($auth));
 *
 *   // inside a controller:
 *   $user = BearerAuth::current();
 *
 * The middleware is a thin enforcement layer over the existing
 * `Rxn\Framework\Service\Auth` service — keeps the authentication
 * mechanism (token shape, lookup, expiry) in one place and lets
 * the pipeline decide *where* in the request lifecycle the check
 * fires.
 */
final class BearerAuth implements MiddlewareInterface
{
    /** @var array<string, mixed>|null */
    private static ?array $current = null;

    public function __construct(
        private readonly Auth $auth,
        private readonly string $headerName = 'Authorization',
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $header    = $request->getHeaderLine($this->headerName);
        $token     = $header !== '' ? $this->auth->extractBearer($header) : null;
        $principal = $this->auth->resolve($token);
        if ($principal === null) {
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
     * Sync-only by design: the framework targets PHP-FPM's
     * process-per-request model (see README, "we deliberately don't
     * chase async"). The clear-in-`finally` pattern is correct under
     * any sync dispatch — including Swoole's default I/O-hooked
     * fibers, where request handlers don't `Fiber::suspend()`
     * between set and clear. If you have a handler that explicitly
     * suspends a fiber while holding state, propagate the principal
     * yourself; don't rely on this static.
     *
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        return self::$current;
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
