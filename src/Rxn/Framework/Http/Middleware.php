<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

/**
 * Backwards-compatible alias for `Psr\Http\Server\MiddlewareInterface`.
 *
 * The framework's middleware contract is now PSR-15. This file
 * exists so that:
 *
 *  1. Existing code that type-hints `Rxn\Framework\Http\Middleware`
 *     keeps compiling (it now accepts any PSR-15 middleware).
 *  2. The "implements Middleware" idiom in third-party Rxn apps
 *     still works — they just need to switch their method body
 *     from `handle(Request, callable): Response` to
 *     `process(ServerRequestInterface, RequestHandlerInterface): ResponseInterface`.
 *
 * New code should depend on `Psr\Http\Server\MiddlewareInterface`
 * directly. This alias will be removed in a later major.
 *
 *   final class RateLimit implements MiddlewareInterface
 *   {
 *       public function __construct(private RateLimiter $rl) {}
 *
 *       public function process(
 *           ServerRequestInterface $request,
 *           RequestHandlerInterface $handler,
 *       ): ResponseInterface {
 *           $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
 *           if (!$this->rl->allow($ip)) {
 *               return new \Nyholm\Psr7\Response(429, [], 'Too Many Requests');
 *           }
 *           return $handler->handle($request);
 *       }
 *   }
 */
interface Middleware extends \Psr\Http\Server\MiddlewareInterface
{
}
