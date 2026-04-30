<?php declare(strict_types=1);

/**
 * Products API — front controller.
 *
 * Routes:
 *   GET    /health                     → liveness + per-check status
 *   GET    /products                   → paginated list, X-Total-Count + Link headers
 *   GET    /products/{id:int}          → single product, 404 if missing
 *   POST   /products                   → create. Bearer auth. Idempotency-Key.
 *                                         Wrapped in a DB transaction.
 *
 * Five framework features in flight:
 *
 *   1. Convention-free explicit Router with typed `{id:int}` constraint
 *   2. `Binder::bind(CreateProduct::class, $body)` for input validation
 *   3. `Pipeline` of middlewares — RequestId, BearerAuth, Idempotency,
 *      Pagination, Transaction
 *   4. RFC 7807 Problem Details for every failure mode
 *   5. `HealthCheck::register` for the readiness endpoint
 */

// Use the framework's own vendor — keeps the example self-contained
// in this monorepo without a second `composer install`.
require __DIR__ . '/../../../vendor/autoload.php';

// Hand-register the example's PSR-4 prefix so we don't need a
// separate composer.json autoloader either.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Example\\Products\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/../app/' . strtr($relative, '\\', '/') . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Example\Products\Dto\CreateProduct;
use Example\Products\Repo\ProductRepo;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Http\Health\HealthCheck;
use Rxn\Framework\Http\Idempotency\FileIdempotencyStore;
use Rxn\Framework\Http\Middleware\BearerAuth;
use Rxn\Framework\Http\Middleware\Idempotency;
use Rxn\Framework\Http\Middleware\Pagination as PaginationMiddleware;
use Rxn\Framework\Http\Pagination\Pagination;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Service\Auth;

// ---- bootstrap ------------------------------------------------------

$dataDir = __DIR__ . '/../var';
@mkdir($dataDir, 0770, true);

$repo = ProductRepo::bootstrap($dataDir . '/products.sqlite');

// Trivial in-process token table — real apps hand the resolver a
// repository or JWT verifier. The `Auth` service stays the same.
$auth = (new \ReflectionClass(Auth::class))->newInstanceWithoutConstructor();
$auth->setResolver(static function (string $token): ?array {
    return match ($token) {
        'demo-admin'  => ['id' => 1, 'name' => 'Admin', 'role' => 'admin'],
        'demo-viewer' => ['id' => 2, 'name' => 'Viewer', 'role' => 'viewer'],
        default       => null,
    };
});

$idempotency = new Idempotency(new FileIdempotencyStore($dataDir . '/idempotency'));
$pagination  = new PaginationMiddleware(defaultLimit: 25, maxLimit: 100);
$bearer      = new BearerAuth($auth);

// ---- routes ---------------------------------------------------------

$router = new Router();

HealthCheck::register($router, '/health', [
    'database' => fn () => $repo->pdo()->query('SELECT 1')->fetchColumn() === 1,
]);

$router->get('/products', function () use ($repo) {
    $page  = Pagination::current() ?? throw new \RuntimeException('Pagination middleware missing');
    $rows  = $repo->paginate(limit: $page->limit, offset: $page->offset);
    $total = $repo->count();
    return [
        'data' => $rows,
        'meta' => ['total' => $total],     // ← Pagination middleware reads this
    ];
})->middleware($pagination);

$router->get('/products/{id:int}', function (array $params) use ($repo) {
    $row = $repo->find((int) $params['id']);
    if ($row === null) {
        return [
            'meta' => [
                'type'   => 'not_found',
                'title'  => "Product {$params['id']} not found",
                'status' => 404,
            ],
        ];
    }
    return ['data' => $row];
});

$router->post('/products', function () use ($repo) {
    $raw  = file_get_contents('php://input') ?: 'null';
    $body = json_decode($raw, true);
    $body = is_array($body) ? $body : [];
    try {
        /** @var CreateProduct $dto */
        $dto = Binder::bind(CreateProduct::class, $body);
    } catch (ValidationException $e) {
        return [
            'meta' => [
                'type'   => 'validation_failed',
                'title'  => 'Invalid request body',
                'status' => 422,
                'errors' => $e->errors(),
            ],
        ];
    }
    $created = $repo->create($dto->name, $dto->price, $dto->status, $dto->homepage);
    return [
        'data' => $created,
        'meta' => [
            'status'         => 201,
            'authenticated'  => BearerAuth::current()['name'] ?? null,
        ],
    ];
})
    ->middleware($bearer)
    ->middleware($idempotency);

// ---- dispatch -------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$hit = $router->match($method, $path);
if ($hit === null) {
    http_response_code(404);
    header('Content-Type: application/problem+json');
    echo json_encode([
        'type'   => 'not_found',
        'title'  => 'Not Found',
        'status' => 404,
    ], JSON_UNESCAPED_SLASHES);
    return;
}

// Build the pipeline. Tiny inline runner — production apps lean on
// App::run() or Psr15Pipeline; this example keeps the dispatcher in
// view to show how the pieces fit. The chain deals only in Response
// objects: the terminal wraps the handler's array result once, then
// every middleware gets and returns a Response.
$middlewares = $hit['middlewares'];

$arrayToResponse = static function (array $body): \Rxn\Framework\Http\Response {
    $r = (new \ReflectionClass(\Rxn\Framework\Http\Response::class))->newInstanceWithoutConstructor();
    $r->data = $body['data'] ?? null;
    $r->meta = $body['meta'] ?? null;
    $codeProp = (new \ReflectionClass(\Rxn\Framework\Http\Response::class))->getProperty('code');
    $codeProp->setAccessible(true);
    $codeProp->setValue($r, (int) ($body['meta']['status'] ?? 200));
    return $r;
};

$terminal = static function () use ($hit, $arrayToResponse): \Rxn\Framework\Http\Response {
    $handler = $hit['handler'];
    $body    = is_callable($handler) ? $handler($hit['params']) : null;
    return $arrayToResponse(is_array($body) ? $body : []);
};

$next = $terminal;
for ($i = count($middlewares) - 1; $i >= 0; $i--) {
    $mw = $middlewares[$i];
    $next = static function () use ($mw, $next): \Rxn\Framework\Http\Response {
        $stub = (new \ReflectionClass(\Rxn\Framework\Http\Request::class))->newInstanceWithoutConstructor();
        return $mw->handle($stub, static fn () => $next());
    };
}

/** @var \Rxn\Framework\Http\Response $response */
$response = $next();

// Render — status + content-type + JSON body.
$status = $response->getCode();
http_response_code($status);
$contentType = $status >= 400 ? 'application/problem+json' : 'application/json';
header('Content-Type: ' . $contentType);
echo json_encode(
    array_filter(['data' => $response->data, 'meta' => $response->meta], static fn ($v) => $v !== null),
    JSON_UNESCAPED_SLASHES,
);
