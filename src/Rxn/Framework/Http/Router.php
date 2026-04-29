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

    /**
     * Routes bucketed by accepted HTTP method. A route registered
     * for ['GET', 'HEAD'] lands in both buckets so `match()` can
     * skip straight to the verb-relevant slice without scanning
     * every entry. Built as `add()` runs; the linear `$routes`
     * list is still authoritative for `hasMethodMismatch()` and
     * `indexNames()`, where ordering across verbs matters.
     *
     * @var array<string, Route[]>
     */
    private array $routesByMethod = [];

    /** @var array<string, Route> */
    private array $named = [];

    /**
     * Per-verb compiled alternation regex + lookup table. The regex
     * combines every route in the bucket with a PCRE `(*MARK:...)`
     * sentinel per alternative, so a single preg_match identifies
     * which route matched. The lookup gives back the Route plus the
     * starting capture-group offset for that route's placeholders.
     *
     * @var array<string, array{
     *     regex: string,
     *     marks: array<string, array{route: Route, firstGroup: int}>
     * }>
     */
    private array $bucketCompiled = [];

    /**
     * Set when add() runs. Cleared by ensureBucketsCompiled() on the
     * next match. Lazy compile lets bulk registration happen with
     * one final compile pass instead of N intermediate ones.
     */
    private bool $bucketsDirty = false;

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
        foreach ($normalized as $m) {
            $this->routesByMethod[$m][] = $route;
        }
        $this->bucketsDirty = true;
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
            // Covers both `{id}` and `{id:int}` forms.
            $url = preg_replace(
                '/\{' . preg_quote($placeholder, '/') . '(?::[a-zA-Z_][a-zA-Z0-9_]*)?\}/',
                (string)$params[$placeholder],
                $url
            );
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
        if ($this->bucketsDirty) {
            $this->compileBuckets();
        }
        $compiled = $this->bucketCompiled[$method] ?? null;
        if ($compiled === null) {
            return null;
        }
        if (!preg_match($compiled['regex'], $path, $matches)) {
            return null;
        }
        // PCRE returns the name of the matched (*MARK:...) sentinel
        // in $matches['MARK']. Fall through to a linear scan only if
        // the runtime somehow didn't surface it (no PCRE2 build I
        // know of misses MARK, but treat it as a safety net).
        $mark = $matches['MARK'] ?? null;
        if ($mark === null || !isset($compiled['marks'][$mark])) {
            return $this->matchLinear($method, $path);
        }
        $hit   = $compiled['marks'][$mark];
        $route = $hit['route'];
        $params = [];
        foreach ($route->paramNames as $i => $name) {
            $params[$name] = $matches[$hit['firstGroup'] + $i];
        }
        return [
            'handler'     => $route->handler,
            'params'      => $params,
            'pattern'     => $route->pattern,
            'name'        => $route->getName(),
            'middlewares' => $route->getMiddlewares(),
        ];
    }

    /**
     * Compile each verb bucket into a single alternation regex.
     * Every route gets a `(*MARK:...)` sentinel so a winning
     * preg_match can name the matching route in one call.
     */
    private function compileBuckets(): void
    {
        $this->bucketCompiled = [];
        foreach ($this->routesByMethod as $verb => $routes) {
            $parts = [];
            $marks = [];
            $groupOffset = 1;
            foreach ($routes as $i => $route) {
                $body = self::stripDelimitersAndAnchors($route->regex);
                $mark = 'r' . $i;
                $parts[] = $body . '(*MARK:' . $mark . ')';
                $marks[$mark] = ['route' => $route, 'firstGroup' => $groupOffset];
                $groupOffset += count($route->paramNames);
            }
            $this->bucketCompiled[$verb] = [
                'regex' => '#^(?:' . implode('|', $parts) . ')$#',
                'marks' => $marks,
            ];
        }
        $this->bucketsDirty = false;
    }

    /**
     * Strip the `#^...$#` wrapper that compile() adds, so the inner
     * pattern can be embedded inside an outer alternation.
     */
    private static function stripDelimitersAndAnchors(string $regex): string
    {
        // compile() always emits `#^<body>$#` where <body> starts
        // with `/`. Drop the `#^` prefix (2 chars) and `$#` suffix
        // (2 chars) so the body — including its leading slash —
        // can be slotted into an outer alternation that re-anchors.
        return substr($regex, 2, -2);
    }

    /**
     * Fallback linear scan; used only if MARK isn't surfaced for
     * some reason. Functionally identical to the pre-alternation
     * implementation.
     */
    private function matchLinear(string $method, string $path): ?array
    {
        foreach ($this->routesByMethod[$method] ?? [] as $route) {
            if (!preg_match($route->regex, $path, $matches)) {
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
     * Named constraint types available inside `{name:type}` placeholders.
     * Custom types can be added via `constraint()` — handy for app-
     * specific formats like `hash`, `year`, etc.
     *
     * @var array<string, string>
     */
    private array $constraints = [
        'int'   => '\d+',
        'slug'  => '[a-z0-9-]+',
        'alpha' => '[a-zA-Z]+',
        'uuid'  => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'any'   => '[^/]+',
    ];

    /**
     * Register (or override) a named regex constraint usable as
     * `{name:type}` in route patterns. The regex must NOT include
     * anchors or capturing groups — it gets inlined inside an outer
     * capture.
     */
    public function constraint(string $name, string $regex): self
    {
        $this->constraints[$name] = $regex;
        return $this;
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
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-zA-Z_][a-zA-Z0-9_]*))?\}$/', $segment, $m)) {
                $params[] = $m[1];
                $type     = $m[2] ?? 'any';
                if (!isset($this->constraints[$type])) {
                    throw new \InvalidArgumentException("Unknown route constraint type '$type'");
                }
                $parts[] = '(' . $this->constraints[$type] . ')';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }
        return ['#^/' . implode('/', $parts) . '$#', $params];
    }
}
