<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware pipeline. Accepts PSR-15 middlewares and a
 * terminal `RequestHandlerInterface`; runs the request through the
 * stack inside-out and returns the response from whichever step
 * handles it (or the terminal, if none short-circuit).
 *
 *   $pipeline = (new Pipeline())
 *       ->add($cors)
 *       ->add($rateLimit)
 *       ->add($auth);
 *
 *   $response = $pipeline->run($request, $controllerHandler);
 *
 * Middleware may short-circuit by returning their own
 * `ResponseInterface` without calling `$handler->handle($request)`,
 * may inspect the response returned by downstream middleware, or
 * may wrap the terminal handler entirely.
 *
 * Pipeline implements `RequestHandlerInterface` so it can itself be
 * used as the inner handler in a larger composition (a pipeline of
 * pipelines, or a route-level pipeline plugged into an app-level
 * one).
 */
final class Pipeline implements RequestHandlerInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];

    private ?RequestHandlerInterface $terminal = null;

    private int $index = 0;

    public function add(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * @return MiddlewareInterface[]
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Run the pipeline against $request, landing on $terminal.
     */
    public function run(ServerRequestInterface $request, RequestHandlerInterface $terminal): ResponseInterface
    {
        $this->terminal = $terminal;
        $this->index    = 0;
        return $this->handle($request);
    }

    /**
     * PSR-15 RequestHandlerInterface implementation. Advances the
     * middleware index on each call; exhausting the middleware
     * list falls through to the terminal handler.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->terminal === null) {
            throw new \LogicException('Pipeline::handle called without a terminal handler; use run().');
        }
        if ($this->index >= count($this->middlewares)) {
            return $this->terminal->handle($request);
        }
        $middleware = $this->middlewares[$this->index++];
        return $middleware->process($request, $this);
    }
}
