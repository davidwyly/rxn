<?php declare(strict_types=1);

/**
 * Quickstart entry point. Demonstrates the modern Rxn shape:
 *
 *   $router = new Router();
 *   $router->get('/path', $handler)->middleware($mw);
 *   App::serve($router);
 *
 * Run with:
 *
 *   php -S 127.0.0.1:9871 -t examples/quickstart/public
 *
 * Then exercise from another shell — see the example's README for
 * curl recipes.
 */

use Example\CreateProduct;
use Example\ProductRepo;
use Psr\Http\Message\ServerRequestInterface;
use Rxn\Framework\App;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Http\Health\HealthCheck;
use Rxn\Framework\Http\Middleware\BearerAuth;
use Rxn\Framework\Http\Middleware\RequestId;
use Rxn\Framework\Http\Router;

require __DIR__ . '/../../../vendor/autoload.php';

// Local autoload for the example's own classes (Example\CreateProduct,
// Example\ProductRepo). The framework's own composer autoload doesn't
// know about this path; rather than ship a separate composer.json
// for the quickstart, a tiny PSR-4 stub keeps the example
// dependency-free at install time.
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Example\\')) {
        return;
    }
    $relative = substr($class, strlen('Example\\'));
    $path     = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// File-backed JSON store (var/quickstart-products.json at the repo
// root). Survives across requests under php -S, php-fpm, etc.
// Real apps swap this for an rxn-orm-backed implementation.
$repo = new ProductRepo();

$router = new Router();

// Health check — no auth, no DTO. The HealthCheck helper builds
// the {data, meta} envelope; App::serve()'s arrayToPsrResponse
// turns it into the right HTTP shape (200 on all-pass,
// 503 + application/problem+json on any failure).
HealthCheck::register($router, '/health', [
    'repo' => fn (): bool => true, // pretend a real check
]);

// One BearerAuth middleware shared across the write routes. The
// resolver maps a token string to a principal array (any shape;
// commonly a user record). Returning null rejects the request
// with a 401 Problem Details.
$auth = new BearerAuth(
    fn (string $token) => $token === 'demo' ? ['id' => 1, 'name' => 'Demo'] : null,
);

// Read routes. Handler signatures match the default invoker's
// `(params, request)` contract — even where unused, declaring
// them documents what gets passed in. RequestId middleware
// injects an X-Request-Id header on the response.
$router->get(
    '/products',
    static fn (array $params, ServerRequestInterface $request): array
        => ['data' => $repo->all()],
)->middleware(new RequestId());

$router->get(
    '/products/{id:int}',
    static function (array $params, ServerRequestInterface $request) use ($repo): array {
        $product = $repo->find((int) $params['id']);
        if ($product === null) {
            return ['meta' => ['status' => 404, 'title' => 'Not Found']];
        }
        return ['data' => $product];
    },
)->middleware(new RequestId());

// Write route — authenticated. Binder hydrates + validates the
// DTO; ValidationException rolls up every failure into one 422
// response (RFC 7807 Problem Details), no one-at-a-time loop.
$router->post(
    '/products',
    static function (array $params, ServerRequestInterface $request) use ($repo): array {
        try {
            $dto = Binder::bindRequest(CreateProduct::class, $request);
        } catch (ValidationException $e) {
            return ['meta' => [
                'status' => 422,
                'title'  => 'Unprocessable Entity',
                'errors' => $e->errors(),
            ]];
        }
        return ['data' => $repo->create($dto), 'meta' => ['status' => 201]];
    },
)->middleware($auth, new RequestId());

App::serve($router);
