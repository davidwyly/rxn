# Routing

Rxn ships a single routing primitive: `Rxn\Framework\Http\Router`.
Drive it directly or populate it from `#[Route]` attributes via
`Rxn\Framework\Http\Attribute\Scanner`.

## Pattern routing

```php
use Rxn\Framework\Http\Router;

$router = new Router();
$router->get('/products/{id}', ProductController::class . '::show');
$router->post('/products', ProductController::class . '::create');
$router->any('/webhooks/{provider}', WebhookController::class . '::ingest');

$hit = $router->match($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
if ($hit === null) {
    if ($router->hasMethodMismatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])) {
        http_response_code(405);
        return;
    }
    http_response_code(404);
    return;
}

// $hit is ['handler' => ..., 'params' => ['id' => '42'], 'pattern' => ...]
```

Verb helpers: `get`, `post`, `put`, `patch`, `delete`, `options`,
`any`. `add()` accepts a single method or an array. Each of them
returns a `Route` handle so you can chain per-route metadata:

```php
$router->get('/products/{id}', ProductController::class . '::show')
       ->name('products.show')
       ->middleware($auth, $rateLimit);
```

`{name}` placeholders capture a single segment (anything up to the
next slash). Static segments are escaped, so dots and dashes in the
pattern match literally.

The first registered route that matches wins — register
specific-to-general when ambiguity is possible.

### Typed placeholders

Narrow a placeholder's regex with `{name:type}`:

```php
$router->get('/users/{id:int}', '...');
$router->get('/posts/{slug:slug}', '...');
$router->get('/sessions/{id:uuid}', '...');
```

Built-in types: `int` (`\d+`), `slug` (`[a-z0-9-]+`), `alpha`
(`[a-zA-Z]+`), `uuid` (canonical 8-4-4-4-12 hex), `any`
(`[^/]+`, the default). Register app-specific ones with
`$router->constraint('year', '\d{4}')`. Typed placeholders turn
what would be a per-controller `is_numeric` check into a routing
concern — a non-matching URL just falls through to the next route
or a 404.

### Attribute-based routing

For apps that prefer co-locating route metadata with the controller,
`Rxn\Framework\Http\Attribute\Scanner` reflects `#[Route]` and
`#[Middleware]` attributes into live `Router` entries. No separate
route table; controllers are the source of truth.

```php
use Rxn\Framework\Http\Attribute\{Route, Middleware};

#[Middleware(Auth::class)]
final class ProductsController
{
    #[Route('GET', '/products/{id:int}', name: 'products.show')]
    public function show(int $id): array { ... }

    #[Route('POST', '/products')]
    #[Middleware(RateLimit::class)]
    public function create(): array { ... }

    #[Route('GET', '/products')]
    #[Route('HEAD', '/products')]    // repeatable — one method, many verbs
    public function index(): array { ... }
}
```

Wire it up once during bootstrap:

```php
use Rxn\Framework\Http\Attribute\Scanner;
use Rxn\Framework\Http\Router;

$router = new Router();
(new Scanner($container))->register($router, [
    ProductsController::class,
    OrdersController::class,
]);
```

Class-level `#[Middleware]` applies to every `#[Route]` on the
class; method-level middleware stacks on after. Middleware classes
are resolved through the container, so autowired deps (loggers,
rate limiters, etc.) work the same way as in a regular service.
The handler stored on each route is `[Controller::class, 'method']`
— your dispatcher invokes it however it likes.

### Named routes

Give a route a stable name and you can reverse-lookup its URL with
params substituted:

```php
$router->get('/products/{id}', ...)->name('products.show');
$router->url('products.show', ['id' => 42]);  // '/products/42'
```

Missing placeholders throw `InvalidArgumentException`; unknown names
throw too.

### Route groups

`group()` scopes a prefix (and optionally a middleware stack) to a
block of routes. Groups nest and inherit their parent's middleware.

```php
$router->group('/api/v1', function (RouteGroup $g) use ($rateLimit, $auth) {
    $g->middleware($rateLimit);
    $g->get('/products/{id}', ProductController::class . '::show');

    $g->group('/admin', function (RouteGroup $g) use ($auth) {
        $g->middleware($auth);
        $g->post('/users', UserController::class . '::create');
        $g->delete('/users/{id}', UserController::class . '::remove');
    });
});
```

`GET /api/v1/products/42` inherits `$rateLimit`; the `/admin/*`
routes inherit `$rateLimit` + `$auth`.

### API versioning — `#[Version]`

`#[Version('v1')]` on a controller method (or class) prefixes
its `#[Route]` paths with `/v1`. The `Scanner` registers each
version as a distinct path, so multiple versions of the same
logical endpoint coexist:

```php
use Rxn\Framework\Http\Attribute\{Route, Version};

class ProductsController
{
    #[Route('GET', '/products/{id:int}')]
    #[Version('v1')]
    public function showV1(int $id): array { /* … */ }

    #[Route('GET', '/products/{id:int}')]
    #[Version('v2')]
    public function showV2(int $id): array { /* … */ }
}
```

After `Scanner::register()`:

- `GET /v1/products/42` → `showV1`
- `GET /v2/products/42` → `showV2`

Class-level `#[Version]` applies to every `#[Route]` in the
class. Method-level wins when both are present.

`bin/rxn routes:check` won't flag cross-version routes as
conflicts — `/v1/products/{id:int}` and `/v2/products/{id:int}`
are different paths in the Router.

#### Deprecation signals

Pass `deprecatedAt` and/or `sunsetAt` (any `DateTimeImmutable`-
parseable date string) and the Scanner auto-attaches a
`Versioning\Deprecation` middleware that emits the matching
RFC 8594 response headers:

```php
#[Route('GET', '/old/{id:int}')]
#[Version('v1', deprecatedAt: '2026-01-01', sunsetAt: '2026-12-31')]
public function show(int $id): array { /* … */ }
```

Outgoing responses gain:

- `Deprecation: Thu, 01 Jan 2026 00:00:00 GMT`
- `Sunset:      Thu, 31 Dec 2026 00:00:00 GMT`

Both are advisory — they don't change response status, just
signal to API clients (and gateways) that the endpoint is on
its way out. Apps that prefer to wire the middleware by hand
can construct it directly:

```php
$router->get('/products', $handler)
    ->middleware(new \Rxn\Framework\Http\Versioning\Deprecation('2026-01-01', '2026-12-31'));
```

### Using matched routes with the pipeline

`Router::match()` returns the matched route's `middlewares` alongside
the handler. A typical dispatcher composes them with the framework's
`Http\Pipeline`:

```php
$hit = $router->match($request->method(), $request->uri());
$pipeline = new Pipeline();
foreach ($hit['middlewares'] as $mw) {
    $pipeline->add($mw);
}
$response = $pipeline->handle($request, fn () => invoke($hit['handler'], $hit['params']));
```
