<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

/**
 * Minimal pattern-based router. Register routes keyed by HTTP method
 * and path pattern; `match()` an incoming (method, path) pair to the
 * first registered route, or null if nothing matches.
 *
 * Patterns accept `{name}` placeholders that capture one segment
 * each (anything up to the next slash). Captured values land in the
 * returned `params` array.
 *
 *   $router = new Router();
 *   $router->get('/products/{id}', ProductController::class . '::show');
 *   $router->post('/products', ProductController::class . '::create');
 *
 *   $hit = $router->match('GET', '/products/42');
 *   // ['handler' => 'ProductController::show', 'params' => ['id' => '42']]
 *
 * Handlers are stored as-is (any type); how to invoke them is the
 * caller's decision — typically via the DI container + Pipeline.
 */
final class Router
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    /**
     * @var array<int, array{methods: string[], regex: string, params: string[], handler: mixed, pattern: string}>
     */
    private array $routes = [];

    /**
     * @param string|string[] $methods
     */
    public function add(array|string $methods, string $pattern, mixed $handler): self
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
        $this->routes[]   = [
            'methods' => $normalized,
            'regex'   => $regex,
            'params'  => $params,
            'handler' => $handler,
            'pattern' => $pattern,
        ];
        return $this;
    }

    public function get(string $pattern, mixed $handler): self     { return $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, mixed $handler): self    { return $this->add('POST', $pattern, $handler); }
    public function put(string $pattern, mixed $handler): self     { return $this->add('PUT', $pattern, $handler); }
    public function patch(string $pattern, mixed $handler): self   { return $this->add('PATCH', $pattern, $handler); }
    public function delete(string $pattern, mixed $handler): self  { return $this->add('DELETE', $pattern, $handler); }
    public function options(string $pattern, mixed $handler): self { return $this->add('OPTIONS', $pattern, $handler); }
    public function any(string $pattern, mixed $handler): self     { return $this->add(self::METHODS, $pattern, $handler); }

    /**
     * Find the first route that matches (method, path). Returns
     * null when no registered route matches the method-path pair.
     *
     * Any query-string portion of $path is stripped before matching
     * so callers can pass a raw REQUEST_URI.
     *
     * @return array{handler: mixed, params: array<string, string>, pattern: string}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path   = parse_url($path, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if (!in_array($method, $route['methods'], true)) {
                $methodMismatch = true;
                continue;
            }
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $matches[$i + 1];
            }
            return [
                'handler' => $route['handler'],
                'params'  => $params,
                'pattern' => $route['pattern'],
            ];
        }
        return null;
    }

    /**
     * True when a route exists at $path but none of them accept
     * $method. Useful for returning a proper 405.
     */
    public function hasMethodMismatch(string $method, string $path): bool
    {
        $method = strtoupper($method);
        $path   = parse_url($path, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        $pathMatched   = false;
        $methodMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path)) {
                continue;
            }
            $pathMatched = true;
            if (in_array($method, $route['methods'], true)) {
                $methodMatched = true;
                break;
            }
        }
        return $pathMatched && !$methodMatched;
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
