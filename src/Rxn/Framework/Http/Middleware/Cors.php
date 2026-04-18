<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

/**
 * Cross-Origin Resource Sharing middleware. Emits the standard
 * Access-Control-* headers and short-circuits preflight (OPTIONS)
 * requests with a 204 before they reach the controller.
 *
 * Configured once at pipeline assembly time; every response gets the
 * same allow-origin treatment. For per-route overrides, attach a
 * second Cors instance at the route level.
 *
 *   new Cors(
 *       allowOrigins: ['https://app.example.com'],
 *       allowMethods: ['GET', 'POST', 'PUT', 'DELETE'],
 *       allowHeaders: ['Content-Type', 'Authorization'],
 *       maxAge:       3600,
 *   );
 *
 * Pass `['*']` to allowOrigins to reflect any origin. Credentials are
 * opt-in (allowCredentials) and silently ignored when the origin is
 * the wildcard — browsers reject that combination anyway.
 */
final class Cors implements Middleware
{
    /** @var string[] */
    private array $allowOrigins;
    /** @var string[] */
    private array $allowMethods;
    /** @var string[] */
    private array $allowHeaders;
    private int $maxAge;
    private bool $allowCredentials;
    /** @var callable(string): void */
    private $emitHeader;
    /** @var callable(int): void */
    private $emitStatus;

    /**
     * @param string[]       $allowOrigins     list of origins, or ['*']
     * @param string[]       $allowMethods
     * @param string[]       $allowHeaders
     * @param ?callable      $emitHeader       injected for testability
     * @param ?callable      $emitStatus       injected for testability
     */
    public function __construct(
        array $allowOrigins = ['*'],
        array $allowMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array $allowHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Request-ID'],
        int $maxAge = 600,
        bool $allowCredentials = false,
        ?callable $emitHeader = null,
        ?callable $emitStatus = null
    ) {
        $this->allowOrigins     = $allowOrigins;
        $this->allowMethods     = $allowMethods;
        $this->allowHeaders     = $allowHeaders;
        $this->maxAge           = $maxAge;
        $this->allowCredentials = $allowCredentials;
        $this->emitHeader       = $emitHeader ?? static fn (string $h) => header($h);
        $this->emitStatus       = $emitStatus ?? static fn (int $code) => http_response_code($code);
    }

    public function handle(Request $request, callable $next): Response
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $this->emitCorsHeaders($origin);

        if ($method === 'OPTIONS') {
            ($this->emitStatus)(204);
            $response = new Response($request);
            $response->setRendered(true);
            return $response;
        }

        return $next($request);
    }

    private function emitCorsHeaders(string $origin): void
    {
        $allowed = $this->resolveOrigin($origin);
        if ($allowed !== null) {
            ($this->emitHeader)("Access-Control-Allow-Origin: $allowed");
            if ($allowed !== '*') {
                ($this->emitHeader)('Vary: Origin');
            }
        }
        ($this->emitHeader)('Access-Control-Allow-Methods: ' . implode(', ', $this->allowMethods));
        ($this->emitHeader)('Access-Control-Allow-Headers: ' . implode(', ', $this->allowHeaders));
        ($this->emitHeader)("Access-Control-Max-Age: {$this->maxAge}");
        if ($this->allowCredentials && $allowed !== null && $allowed !== '*') {
            ($this->emitHeader)('Access-Control-Allow-Credentials: true');
        }
    }

    private function resolveOrigin(string $origin): ?string
    {
        if (in_array('*', $this->allowOrigins, true)) {
            return $this->allowCredentials && $origin !== '' ? $origin : '*';
        }
        if ($origin !== '' && in_array($origin, $this->allowOrigins, true)) {
            return $origin;
        }
        return null;
    }
}
