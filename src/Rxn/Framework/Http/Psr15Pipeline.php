<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15-native companion to Rxn\Framework\Http\Pipeline. Accepts
 * Psr\Http\Server\MiddlewareInterface implementations and a
 * terminal RequestHandlerInterface, so any middleware from the PHP
 * ecosystem (CORS, session, rate limiters, OAuth, tracing, ...)
 * drops straight in.
 *
 *   $pipeline = (new Psr15Pipeline())
 *       ->add($cors)
 *       ->add($rateLimit)
 *       ->add($auth);
 *
 *   $response = $pipeline->handle($request, $controllerHandler);
 */
final class Psr15Pipeline implements RequestHandlerInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];

    /** @var RequestHandlerInterface|null */
    private ?RequestHandlerInterface $terminal = null;

    /** @var int */
    private int $index = 0;

    public function add(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Run the pipeline against $request, landing on $terminal.
     */
    public function run(ServerRequestInterface $request, RequestHandlerInterface $terminal): ResponseInterface
    {
        $this->terminal = $terminal;
        $this->index    = 0;

        try {
            return $this->handle($request);
        } finally {
            $this->terminal = null;
            $this->index    = 0;
        }
    }

    /**
     * PSR-15 RequestHandlerInterface implementation. Advances the
     * middleware index on each call; exhausting the middleware
     * list falls through to the terminal handler.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->terminal === null) {
            throw new \LogicException('Psr15Pipeline::handle called without a terminal handler; use run().');
        }
        if ($this->index >= count($this->middlewares)) {
            return $this->terminal->handle($request);
        }
        $middleware = $this->middlewares[$this->index++];
        return $middleware->process($request, $this);
    }
}
