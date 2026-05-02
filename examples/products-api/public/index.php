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
 *   3. PSR-15 `Pipeline` of middlewares — RequestId, BearerAuth,
 *      Idempotency, Pagination, Transaction
 *   4. RFC 7807 Problem Details for every failure mode
 *   5. `HealthCheck::register` for the readiness endpoint
 *
 * The whole front controller is plain PSR-7/15: ServerRequest in
 * via `PsrAdapter::serverRequestFromGlobals`, threaded through a
 * PSR-15 `Pipeline`, ResponseInterface out via `PsrAdapter::emit`.
 * No reflection-stubbed Request/Response objects, no side-channel
 * `header()` calls — every middleware modifies the response on the
 * way out via PSR-7's `withHeader`.
 */

require __DIR__ . '/../../../vendor/autoload.php';

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
use Psr\Http\Message\ServerRequestInterface;
use Rxn\Framework\App;
use Rxn\Framework\Concurrency\HttpClient as AsyncHttpClient;
use Rxn\Framework\Concurrency\Scheduler;
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

use function Rxn\Framework\Concurrency\awaitAll;

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

$router->post('/products', function (array $params, ServerRequestInterface $request) use ($repo) {
    try {
        // bindRequest reads ?query + parsedBody (or decodes a JSON
        // body inline) — no dependency on JsonBody middleware
        // having mutated $_POST first.
        /** @var CreateProduct $dto */
        $dto = Binder::bindRequest(CreateProduct::class, $request);
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

// ---- dashboard fan-out (fiber-await demo) ---------------------------
//
// GET /dashboard/{id}?mode=parallel|sequential
//
// A "compose a response from N upstream microservices" route, the
// shape that gives the fiber-await mechanism its win. Hits three
// fake-upstream backends (boot them with `bench/fiber/backend.php`
// on ports 8101/8102/8103 — see the README), in either:
//
//   ?mode=sequential — three blocking file_get_contents in series
//                      (~300ms wall-clock, three 100ms sleeps stacked)
//   ?mode=parallel   — three curl handles overlapped via the
//                      Scheduler/Promise/awaitAll machinery
//                      (~100ms wall-clock — bound by the slowest)
//
// The response's `meta.wall_clock_ms` shows the difference live.
// Same five lines of fan-out, two completely different latency
// profiles. Sync code outside the `Scheduler::run()` body is
// untouched — handlers that don't compose upstream calls don't
// pay any cost.
$router->get('/dashboard/{id:int}', function (array $params, ServerRequestInterface $request): array {
    $id   = (int) $params['id'];
    $mode = $request->getQueryParams()['mode'] ?? 'parallel';

    $base = getenv('DASHBOARD_BACKEND_BASE') ?: 'http://127.0.0.1';
    $urls = [
        'inventory' => "$base:8101/inventory/$id",
        'pricing'   => "$base:8102/pricing/$id",
        'reviews'   => "$base:8103/reviews/$id",
    ];

    $started = hrtime(true);
    if ($mode === 'sequential') {
        $bodies = [];
        foreach ($urls as $key => $url) {
            $bodies[$key] = (string) @file_get_contents($url);
        }
    } else {
        $scheduler = new Scheduler();
        $client    = new AsyncHttpClient($scheduler);
        $bodies = $scheduler->run(static fn (): array => awaitAll(array_map(
            static fn (string $url) => $client->getAsync($url),
            $urls,
        )));
    }
    $wallClockMs = (hrtime(true) - $started) / 1e6;

    $decoded = [];
    foreach ($bodies as $key => $body) {
        $decoded[$key] = $body !== '' ? json_decode($body, true) : null;
    }

    return [
        'data' => $decoded,
        'meta' => [
            'mode'          => $mode,
            'fanout'        => count($urls),
            'wall_clock_ms' => round($wallClockMs, 1),
        ],
    ];
});

// ---- dispatch -------------------------------------------------------

// `App::serve()` builds the PSR-7 ServerRequest from globals, runs
// it through the route's middleware Pipeline, invokes the matched
// handler via the framework's default invoker, and emits a PSR-7
// response. The `(array $hit, ServerRequestInterface $req)` invoker
// signature lets handlers receive the request without further
// indirection — handlers in this app return arrays which `App::serve`
// wraps in the standard `{data, meta}` envelope.
//
// Apps that prefer the explicit wire-up (the shape of this file
// before this commit) can still call `PsrAdapter::serverRequestFromGlobals()`
// + `Pipeline::run()` + `PsrAdapter::emit()` directly — `App::serve`
// is sugar, not magic.
//
// Apps using convention routing (/v{N}/{controller}/{action})
// instead of explicit Router stay on `App::run()` — `serve()`
// does not replace it.

App::serve($router);
