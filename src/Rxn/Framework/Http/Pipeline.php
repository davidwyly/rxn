<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

/**
 * Runs a Request through an ordered stack of Middleware and lands
 * on a terminal callable that produces the Response.
 *
 *   $pipeline = (new Pipeline())
 *       ->add($cors)
 *       ->add($rateLimit)
 *       ->add($auth);
 *
 *   $response = $pipeline->handle(
 *       $request,
 *       fn (Request $req) => $controller->dispatch($req)
 *   );
 *
 * Middleware may short-circuit by returning a Response without
 * calling `$next`, may inspect the Response returned by downstream
 * middleware, or may wrap the terminal handler entirely.
 */
final class Pipeline
{
    /** @var Middleware[] */
    private array $middlewares = [];

    public function add(Middleware $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * @return Middleware[]
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Execute the pipeline. $terminal is the innermost handler —
     * typically a controller dispatcher — receiving the Request that
     * has been threaded through every middleware.
     *
     * @param callable(Request): Response $terminal
     */
    public function handle(Request $request, callable $terminal): Response
    {
        // Build the chain from the inside out: the innermost call is
        // $terminal, and each middleware wraps the next step. Walk
        // the array backward by index instead of array_reverse() so
        // the per-handle() reversed-array allocation drops out.
        $next = $terminal;
        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i];
            $next = static function (Request $req) use ($middleware, $next): Response {
                return $middleware->handle($req, $next);
            };
        }
        return $next($request);
    }
}
