<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cross-Origin Resource Sharing middleware. Adds the standard
 * Access-Control-* headers to every response and short-circuits
 * preflight (OPTIONS) requests with a 204 before they reach the
 * downstream handler.
 *
 * Configured once at pipeline assembly time; every response gets
 * the same allow-origin treatment. For per-route overrides, attach
 * a second Cors instance at the route level.
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
final class Cors implements MiddlewareInterface
{
    /** @var string[] */
    private array $allowOrigins;
    /** @var string[] */
    private array $allowMethods;
    /** @var string[] */
    private array $allowHeaders;
    private int $maxAge;
    private bool $allowCredentials;

    /**
     * @param string[] $allowOrigins list of origins, or ['*']
     * @param string[] $allowMethods
     * @param string[] $allowHeaders
     */
    public function __construct(
        array $allowOrigins = ['*'],
        array $allowMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array $allowHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Request-ID'],
        int $maxAge = 600,
        bool $allowCredentials = false,
    ) {
        $this->allowOrigins     = $allowOrigins;
        $this->allowMethods     = $allowMethods;
        $this->allowHeaders     = $allowHeaders;
        $this->maxAge           = $maxAge;
        $this->allowCredentials = $allowCredentials;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $origin = $request->getHeaderLine('Origin');
        $method = strtoupper($request->getMethod());

        if ($method === 'OPTIONS') {
            return $this->withCorsHeaders(new Psr7Response(204), $origin);
        }

        $response = $handler->handle($request);
        return $this->withCorsHeaders($response, $origin);
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $allowed = $this->resolveOrigin($origin);
        if ($allowed !== null) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $allowed);
            if ($allowed !== '*') {
                $response = $response->withAddedHeader('Vary', 'Origin');
            }
        }
        $response = $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowHeaders))
            ->withHeader('Access-Control-Max-Age', (string)$this->maxAge);
        if ($this->allowCredentials && $allowed !== null && $allowed !== '*') {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }
        return $response;
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
