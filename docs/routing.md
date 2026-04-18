# Routing

Rxn offers two routing styles. Use whichever fits each endpoint;
they coexist in the same app.

## Convention-based routing

A default request URL is `version/controller/action` followed by any
number of `key/value` pairs:

```
https://yourapp.tld/v2.1/order/doSomething
https://yourapp.tld/v2.1/order/doSomething/id/1234
```

In the example above:

- `v2.1` is the endpoint version. The `2` is the controller version
  and the `1` is the action version.
- `order` is the controller.
- `doSomething` is the action (a public method on the controller).

An odd number of params after the action (so a dangling key with
no value) yields an error response.

### Versioned controllers and actions

Version numbers in the URL map directly to controller namespaces
and action suffixes:

```php
namespace Organization\Product\Controller\v2;

class Order extends \Rxn\Framework\Http\Controller
{
    public function doSomething_v1()
    {
        // ...
    }
}
```

Versioning lets you ship a new behaviour alongside the old one so
frontend clients don't break silently on deploy.

## Explicit pattern routing

For endpoints that don't fit the convention — webhooks, nested
resources, non-REST endpoints — use `Rxn\Framework\Http\Router`:

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
