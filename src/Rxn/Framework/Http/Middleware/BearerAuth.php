<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;
use Rxn\Framework\Service\Auth;

/**
 * Stateless Bearer-token authentication. Reads the
 * `Authorization: Bearer <token>` header, hands the token to the
 * configured `Auth` resolver, and either:
 *
 *  - 401 Problem Details on missing / malformed / unrecognised
 *    token, or
 *  - delegates to `$next` and exposes the resolved principal via
 *    `BearerAuth::current()` for downstream code.
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
final class BearerAuth implements Middleware
{
    /** @var array<string, mixed>|null */
    private static ?array $current = null;

    public function __construct(
        private readonly Auth $auth,
        private readonly string $headerName = 'Authorization',
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $serverKey = 'HTTP_' . strtoupper(strtr($this->headerName, '-', '_'));
        $header    = $_SERVER[$serverKey] ?? null;
        $token     = is_string($header) ? $this->auth->extractBearer($header) : null;
        $principal = $this->auth->resolve($token);
        if ($principal === null) {
            return $this->renderUnauthorized();
        }
        self::$current = $principal;
        try {
            return $next($request);
        } finally {
            self::$current = null;
        }
    }

    /**
     * Returns the authenticated principal for the current request,
     * or null when no `BearerAuth` is on the pipeline (or the
     * request hasn't reached it yet). Cleared in `handle()`'s
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

    private function renderUnauthorized(): Response
    {
        // Goes through the public Problem Details factory so the
        // 401 lands as `application/problem+json` (matching the
        // framework's RFC 7807 commitment), instead of leaking
        // through as an envelope-shaped 401.
        return Response::problem(
            code:   401,
            title:  'Unauthorized',
            detail: 'Authentication required',
        );
    }
}
