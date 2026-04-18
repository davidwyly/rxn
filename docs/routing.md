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
`any`. `add()` accepts a single method or an array.

`{name}` placeholders capture a single segment (anything up to the
next slash). Static segments are escaped, so dots and dashes in the
pattern match literally.

The first registered route that matches wins — register
specific-to-general when ambiguity is possible.
