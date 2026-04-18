<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

/**
 * Contract for a single step in a request pipeline. Implementations
 * may short-circuit by returning their own Response, or delegate to
 * the next step by invoking `$next($request)` and returning (or
 * post-processing) its result.
 *
 *   final class RateLimit implements Middleware
 *   {
 *       public function __construct(private RateLimiter $rl) {}
 *
 *       public function handle(Request $request, callable $next): Response
 *       {
 *           if (!$this->rl->allow($request->clientIp())) {
 *               throw new \Exception('Too Many Requests', 429);
 *           }
 *           return $next($request);
 *       }
 *   }
 */
interface Middleware
{
    /**
     * @param Request  $request
     * @param callable $next A function(Request $request): Response. Invoke
     *                       it to delegate to the rest of the pipeline.
     */
    public function handle(Request $request, callable $next): Response;
}
