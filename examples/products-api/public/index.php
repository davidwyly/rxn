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
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Http\Health\HealthCheck;
use Rxn\Framework\Http\Idempotency\FileIdempotencyStore;
use Rxn\Framework\Http\Middleware\BearerAuth;
use Rxn\Framework\Http\Middleware\Idempotency;
use Rxn\Framework\Http\Middleware\Pagination as PaginationMiddleware;
use Rxn\Framework\Http\Pagination\Pagination;
use Rxn\Framework\Http\Pipeline;
use Rxn\Framework\Http\PsrAdapter;
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

$router->post('/products', function (array $params, ServerRequestInterface $request) use ($repo) {
    $raw  = (string) $request->getBody();
    $body = json_decode($raw === '' ? 'null' : $raw, true);
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

$request = PsrAdapter::serverRequestFromGlobals();
$method  = $request->getMethod();
$path    = $request->getUri()->getPath();

$hit = $router->match($method, $path);
if ($hit === null) {
    PsrAdapter::emit(problem(404, 'Not Found'));
    return;
}

$pipeline = new Pipeline();
foreach ($hit['middlewares'] as $mw) {
    $pipeline->add($mw);
}

$terminal = new class($hit) implements RequestHandlerInterface {
    public function __construct(private array $hit) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $this->hit['handler'];
        $body    = is_callable($handler) ? $handler($this->hit['params'], $request) : [];
        return arrayToPsrResponse(is_array($body) ? $body : []);
    }
};

PsrAdapter::emit($pipeline->run($request, $terminal));

// ---- helpers --------------------------------------------------------

/**
 * Convert the example's array-returning handler shape to a PSR-7
 * response. Status defaults to 200, or to `meta.status` when the
 * handler set one — middleware can still further mutate the
 * response on the way out (Pagination headers, ETag, etc.).
 *
 * Failure shapes (4xx / 5xx) get `application/problem+json`;
 * successes get `application/json` with the framework's
 * `{data, meta}` envelope.
 */
function arrayToPsrResponse(array $body): ResponseInterface
{
    $status      = (int) ($body['meta']['status'] ?? 200);
    $isFailure   = $status >= 400;
    $contentType = $isFailure ? 'application/problem+json' : 'application/json';

    $payload = $isFailure
        ? [
            'type'   => $body['meta']['type']   ?? 'about:blank',
            'title'  => $body['meta']['title']  ?? '',
            'status' => $status,
            'errors' => $body['meta']['errors'] ?? null,
        ]
        : array_filter(
            ['data' => $body['data'] ?? null, 'meta' => $body['meta'] ?? null],
            static fn ($v) => $v !== null,
        );

    if ($isFailure && $payload['errors'] === null) {
        unset($payload['errors']);
    }

    return new Psr7Response(
        $status,
        ['Content-Type' => $contentType],
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}

function problem(int $status, string $title): ResponseInterface
{
    return new Psr7Response(
        $status,
        ['Content-Type' => 'application/problem+json'],
        json_encode([
            'type'   => 'about:blank',
            'title'  => $title,
            'status' => $status,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}
