<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

use Psr\Http\Server\MiddlewareInterface;

/**
 * Handle returned by Router::add() (and the verb helpers). Mutates
 * in place; callers can chain name() and middleware() to attach
 * per-route metadata.
 */
final class Route
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    private ?string $name = null;

    /**
     * @param string[] $methods   HTTP verbs this route accepts
     * @param string   $regex     compiled regex used for matching
     * @param string[] $paramNames placeholder names, in pattern order
     * @param mixed    $handler   opaque; the caller decides invocation
     * @param string   $pattern   original registration pattern
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $regex,
        public readonly array $paramNames,
        public readonly mixed $handler,
        public readonly string $pattern,
    ) {}

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function middleware(MiddlewareInterface ...$middlewares): self
    {
        foreach ($middlewares as $m) {
            $this->middlewares[] = $m;
        }
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return MiddlewareInterface[] */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
