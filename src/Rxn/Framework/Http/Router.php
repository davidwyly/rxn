<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

/**
 * Pattern-based router. Register routes via add() or a verb helper;
 * match() an incoming (method, path) pair to a Route or null.
 *
 *   $router = new Router();
 *   $router->get('/products/{id}', ProductController::class . '::show')
 *          ->name('products.show')
 *          ->middleware($auth);
 *
 *   $router->group('/api/v1', function (RouteGroup $g) use ($rateLimit) {
 *       $g->middleware($rateLimit);
 *       $g->get('/health', HealthController::class . '::ping');
 *   });
 *
 *   $hit = $router->match('GET', '/products/42');
 *   // $hit['handler'] / $hit['params'] / $hit['middlewares'] / ...
 *
 *   $router->url('products.show', ['id' => 42]);
 *   // '/products/42'
 *
 * Handlers are stored as-is (any type); callers decide invocation —
 * typically wrap them in a Pipeline composed of the route's
 * middlewares plus a terminal dispatcher.
 */
final class Router
{
    /** @internal */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /**
     * @param string|string[] $methods
     */
    public function add(array|string $methods, string $pattern, mixed $handler): Route
    {
        $methods    = is_array($methods) ? $methods : [$methods];
        $normalized = [];
        foreach ($methods as $m) {
            $upper = strtoupper((string)$m);
            if (!in_array($upper, self::METHODS, true)) {
                throw new \InvalidArgumentException("Unsupported HTTP method '$upper'");
            }
            $normalized[] = $upper;
        }
        [$regex, $params] = $this->compile($pattern);
        $route = new Route($normalized, $regex, $params, $handler, $pattern);
        $this->routes[] = $route;
        return $route;
    }

    public function get(string $pattern, mixed $handler): Route     { return $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, mixed $handler): Route    { return $this->add('POST', $pattern, $handler); }
    public function put(string $pattern, mixed $handler): Route     { return $this->add('PUT', $pattern, $handler); }
    public function patch(string $pattern, mixed $handler): Route   { return $this->add('PATCH', $pattern, $handler); }
    public function delete(string $pattern, mixed $handler): Route  { return $this->add('DELETE', $pattern, $handler); }
    public function options(string $pattern, mixed $handler): Route { return $this->add('OPTIONS', $pattern, $handler); }
    public function any(string $pattern, mixed $handler): Route     { return $this->add(self::METHODS, $pattern, $handler); }

    /**
     * Register a group of routes under a shared path prefix. The
     * callable receives a RouteGroup; every route it registers
     * automatically gets the prefix prepended and any group
     * middleware attached.
     */
    public function group(string $prefix, callable $definer): void
    {
        $this->groupOn($prefix, [], $definer);
    }

    /** @internal */
    public function groupOn(string $prefix, array $middlewares, callable $definer): void
    {
        $group = new RouteGroup($this, $prefix, $middlewares);
        $definer($group);
    }

    /**
     * Look up a named route by its declared name and substitute
     * `{placeholder}` params. Throws if no such name exists or a
     * required placeholder is missing.
     *
     * @param array<string, int|string> $params
     */
    public function url(string $name, array $params = []): string
    {
        $this->indexNames();
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException("No route named '$name'");
        }
        $route = $this->named[$name];
        $url   = $route->pattern;
        foreach ($route->paramNames as $placeholder) {
            if (!array_key_exists($placeholder, $params)) {
                throw new \InvalidArgumentException("Missing param '$placeholder' for route '$name'");
            }
            $url = str_replace('{' . $placeholder . '}', (string)$params[$placeholder], $url);
        }
        return $url;
    }

    /**
     * Find the first route that matches (method, path).
     *
     * @return array{handler: mixed, params: array<string, string>, pattern: string, name: ?string, middlewares: Middleware[]}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path   = $this->normalizePath($path);
        if ($path === null) {
            return null;
        }
        foreach ($this->routes as $route) {
            if (!preg_match($route->regex, $path, $matches)) {
                continue;
            }
            if (!in_array($method, $route->methods, true)) {
                continue;
            }
            $params = [];
            foreach ($route->paramNames as $i => $name) {
                $params[$name] = $matches[$i + 1];
            }
            return [
                'handler'     => $route->handler,
                'params'      => $params,
                'pattern'     => $route->pattern,
                'name'        => $route->getName(),
                'middlewares' => $route->getMiddlewares(),
            ];
        }
        return null;
    }

    /**
     * True when a route exists at $path but none of them accept
     * $method. Lets callers return a proper 405 with an Allow header.
     */
    public function hasMethodMismatch(string $method, string $path): bool
    {
        $method = strtoupper($method);
        $path   = $this->normalizePath($path);
        if ($path === null) {
            return false;
        }
        $pathMatched   = false;
        $methodMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route->regex, $path)) {
                continue;
            }
            $pathMatched = true;
            if (in_array($method, $route->methods, true)) {
                $methodMatched = true;
                break;
            }
        }
        return $pathMatched && !$methodMatched;
    }

    private function indexNames(): void
    {
        $this->named = [];
        foreach ($this->routes as $route) {
            $n = $route->getName();
            if ($n !== null) {
                $this->named[$n] = $route;
            }
        }
    }

    private function normalizePath(string $path): ?string
    {
        $path = parse_url($path, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    /**
     * @return array{0: string, 1: string[]} [regex, paramNames]
     */
    private function compile(string $pattern): array
    {
        $pattern = '/' . ltrim($pattern, '/');
        if ($pattern !== '/') {
            $pattern = rtrim($pattern, '/');
        }
        if ($pattern === '/') {
            return ['#^/$#', []];
        }

        $params = [];
        $parts  = [];
        foreach (explode('/', ltrim($pattern, '/')) as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $m)) {
                $params[] = $m[1];
                $parts[]  = '([^/]+)';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }
        return ['#^/' . implode('/', $parts) . '$#', $params];
    }
}
