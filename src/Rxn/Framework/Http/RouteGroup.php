<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

/**
 * Scoped proxy returned to the Router::group() callback. Every
 * route registered through it picks up the group's path prefix
 * and inherits the group's middleware chain.
 *
 *   $router->group('/api/v1', function (RouteGroup $g) use ($auth) {
 *       $g->middleware($auth);
 *       $g->get('/products/{id}', ProductController::class . '::show')
 *         ->name('products.show');
 *       $g->group('/admin', function (RouteGroup $g) use ($adminOnly) {
 *           $g->middleware($adminOnly);
 *           $g->post('/users', UserController::class . '::create');
 *       });
 *   });
 *
 * Groups nest; inner groups stack their prefix onto the outer one
 * and inherit the outer's middleware list.
 */
final class RouteGroup
{
    /** @var Middleware[] */
    private array $middlewares;

    public function __construct(
        private readonly Router $router,
        private readonly string $prefix,
        array $middlewares = []
    ) {
        $this->middlewares = $middlewares;
    }

    public function middleware(Middleware ...$middlewares): self
    {
        foreach ($middlewares as $m) {
            $this->middlewares[] = $m;
        }
        return $this;
    }

    /**
     * @param string|string[] $methods
     */
    public function add(array|string $methods, string $pattern, mixed $handler): Route
    {
        $route = $this->router->add($methods, $this->prefix . $pattern, $handler);
        foreach ($this->middlewares as $m) {
            $route->middleware($m);
        }
        return $route;
    }

    public function get(string $pattern, mixed $handler): Route     { return $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, mixed $handler): Route    { return $this->add('POST', $pattern, $handler); }
    public function put(string $pattern, mixed $handler): Route     { return $this->add('PUT', $pattern, $handler); }
    public function patch(string $pattern, mixed $handler): Route   { return $this->add('PATCH', $pattern, $handler); }
    public function delete(string $pattern, mixed $handler): Route  { return $this->add('DELETE', $pattern, $handler); }
    public function options(string $pattern, mixed $handler): Route { return $this->add('OPTIONS', $pattern, $handler); }
    public function any(string $pattern, mixed $handler): Route     { return $this->add(Router::METHODS, $pattern, $handler); }

    public function group(string $prefix, callable $definer): void
    {
        $this->router->groupOn($this->prefix . $prefix, $this->middlewares, $definer);
    }
}
