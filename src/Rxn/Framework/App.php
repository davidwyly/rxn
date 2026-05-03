<?php declare(strict_types=1);

namespace Rxn\Framework;

use \Rxn\Framework\Http\Request;
use \Rxn\Framework\Http\Response;
use \Rxn\Framework\Service\Api;
use \Rxn\Framework\Error\AppException;

/**
 * Application entrypoint. Constructor boots the environment
 * (Startup registers constants, loads .env, wires databases);
 * run() resolves the request, dispatches to the matching controller
 * action via the container, and renders the JSON envelope.
 *
 * Apps that want an explicit router or PSR-15 middleware pipeline
 * instead can bypass run() and drive the primitives in
 * Rxn\Framework\Http\Router / Pipeline / PsrAdapter directly — the
 * container, Request, Controller, and Response classes work either
 * way.
 */
class App
{
    private Container $container;

    /**
     * Convention-router state. Used internally by `dispatch()` to
     * route the incoming request to a controller; no external
     * consumer should reach in. Exposed via `api()` for the rare
     * test that needs to peek.
     */
    private Api $api;

    /**
     * Boot-time stats. Null when the Stats service couldn't load
     * (e.g. the app's Stats binding fails); environment errors
     * collect on `self::$environment_errors` in that case.
     */
    private ?Service\Stats $stats = null;

    /** @var \Exception[] */
    private static $environment_errors = [];

    public function __construct()
    {
        $this->container = new Container();
        $this->container->get(Startup::class);

        // Service\Registry used to be eagerly constructed here, which
        // forced a MySQL connection during boot — every request,
        // including 404s and /health checks, depended on the database
        // being reachable. Registry's actual consumers (legacy
        // `Model\Record`, `Data\Map`) pull it from the container on
        // first access; apps not using those code paths never touch
        // the schema. Convention router boot is now database-free.
        $this->api = $this->container->get(Api::class);

        try {
            $this->stats = $this->container->get(Service\Stats::class);
            $this->stats->stop(START);
        } catch (\Exception $e) {
            self::appendEnvironmentError($e);
        }
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Read access to the convention-router state. Mostly useful
     * for tests that need to inspect the resolved controller.
     */
    public function api(): Api
    {
        return $this->api;
    }

    public function stats(): ?Service\Stats
    {
        return $this->stats;
    }

    /**
     * PSR-7/15-native entry point — the recommended shape for new
     * apps. Builds a `ServerRequestInterface` from globals, runs
     * it through the route's middleware `Pipeline`, invokes the
     * matched handler, and emits a `ResponseInterface`.
     *
     *   $router = new Router();
     *   $router->get('/products/{id:int}', [ProductController::class, 'show']);
     *   App::serve($router);
     *
     * Handler return shapes accepted by the default invoker:
     *
     *  - `ResponseInterface` — returned as-is
     *  - `array` — wrapped as `{data, meta}` JSON envelope, status
     *    from `meta.status` (defaults to 200)
     *  - anything else — wrapped as `{data: <value>}` envelope
     *
     * Apps that need a custom invoker (e.g. to autowire DTO
     * parameters via `Binder::bindRequest`) pass it as the second
     * argument:
     *
     *   App::serve($router, function (array $hit, ServerRequestInterface $req) use ($container): ResponseInterface { ... });
     *
     * **Static and boot-free.** Doesn't require a Startup-loaded
     * `App` instance, doesn't read env constants, doesn't touch
     * the convention-router service graph. Apps that opted into
     * the explicit Router can run on a minimal `composer require
     * rxn` install with no boot configuration. Existing apps
     * using convention routing keep using `App::run()` — `serve()`
     * doesn't replace it.
     *
     * @param callable(array, \Psr\Http\Message\ServerRequestInterface): \Psr\Http\Message\ResponseInterface|null $invoker
     */
    public static function serve(\Rxn\Framework\Http\Router $router, ?callable $invoker = null): void
    {
        $request = \Rxn\Framework\Http\PsrAdapter::serverRequestFromGlobals();
        $method  = $request->getMethod();
        $path    = $request->getUri()->getPath();

        // Gate every observability cost on a dispatcher actually
        // being installed: pair-id minting (random_bytes), event
        // construction, and dispatch all skip when nobody is
        // listening. Apps that don't subscribe pay one method call
        // per emit point — no allocations, no CSPRNG draws.
        $eventsEnabled = \Rxn\Framework\Observability\Events::enabled();
        $pairId        = $eventsEnabled
            ? \Rxn\Framework\Observability\Events::newPairId()
            : null;

        try {
            if ($eventsEnabled && $pairId !== null) {
                \Rxn\Framework\Observability\Events::useCurrentPairId($pairId);
                \Rxn\Framework\Observability\Events::emit(
                    new \Rxn\Framework\Observability\Event\RequestReceived($pairId, $request)
                );
            }

            $hit = $router->match($method, $path);
            if ($hit === null) {
                $status   = $router->hasMethodMismatch($method, $path) ? 405 : 404;
                $response = self::psrProblem($status);
                \Rxn\Framework\Http\PsrAdapter::emit($response);
                if ($eventsEnabled && $pairId !== null) {
                    \Rxn\Framework\Observability\Events::emit(
                        new \Rxn\Framework\Observability\Event\ResponseEmitted($pairId, $response)
                    );
                }
                return;
            }

            $pipeline = new \Rxn\Framework\Http\Pipeline();
            foreach ($hit['middlewares'] as $mw) {
                $pipeline->add($mw);
            }

            $invoker ??= self::defaultHandlerInvoker(...);

            // Wrap the user invoker with HandlerInvoked entered/exited
            // events when observability is enabled; otherwise the
            // bare invoker runs untouched.
            $finalInvoker = ($eventsEnabled && $pairId !== null)
                ? self::wrapInvokerWithHandlerEvents($invoker, $pairId)
                : $invoker;

            $terminal = new class($hit, $finalInvoker) implements \Psr\Http\Server\RequestHandlerInterface {
                /** @param callable(array, \Psr\Http\Message\ServerRequestInterface): \Psr\Http\Message\ResponseInterface $invoker */
                public function __construct(private array $hit, private $invoker) {}

                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return ($this->invoker)($this->hit, $request);
                }
            };

            $response = $pipeline->run($request, $terminal);

            \Rxn\Framework\Http\PsrAdapter::emit($response);
            if ($eventsEnabled && $pairId !== null) {
                \Rxn\Framework\Observability\Events::emit(
                    new \Rxn\Framework\Observability\Event\ResponseEmitted($pairId, $response)
                );
            }
        } finally {
            // Clear the request-scoped pair id so a long-running
            // worker (Swoole / RoadRunner) doesn't leak it into the
            // next request. The slot is intentionally process-wide
            // (sync PHP serves one request at a time per worker),
            // and we want it gone before the next request begins.
            if ($eventsEnabled) {
                \Rxn\Framework\Observability\Events::useCurrentPairId(null);
            }
        }
    }

    /**
     * Decorate `$invoker` with `HandlerInvoked` entered/exited
     * events, both shared with the request's pair id. The exited
     * event fires both on success and on throw; the throwable is
     * re-thrown so the pipeline / global error handler still sees
     * the original.
     */
    private static function wrapInvokerWithHandlerEvents(callable $invoker, string $pairId): callable
    {
        return static function (array $hit, \Psr\Http\Message\ServerRequestInterface $req) use ($invoker, $pairId): \Psr\Http\Message\ResponseInterface {
            $handlerLabel = self::describeHandler($hit['handler'] ?? null);
            \Rxn\Framework\Observability\Events::emit(
                new \Rxn\Framework\Observability\Event\HandlerInvoked(
                    $pairId,
                    \Rxn\Framework\Observability\Event\HandlerInvoked::STATE_ENTERED,
                    $handlerLabel,
                )
            );
            try {
                $response = $invoker($hit, $req);
                \Rxn\Framework\Observability\Events::emit(
                    new \Rxn\Framework\Observability\Event\HandlerInvoked(
                        $pairId,
                        \Rxn\Framework\Observability\Event\HandlerInvoked::STATE_EXITED,
                        $handlerLabel,
                    )
                );
                return $response;
            } catch (\Throwable $e) {
                \Rxn\Framework\Observability\Events::emit(
                    new \Rxn\Framework\Observability\Event\HandlerInvoked(
                        $pairId,
                        \Rxn\Framework\Observability\Event\HandlerInvoked::STATE_EXITED,
                        $handlerLabel,
                        $e,
                    )
                );
                throw $e;
            }
        };
    }

    /**
     * Stringify a route handler for use as a span name. Mapping:
     *
     *   - `\Closure`                       → `"Closure"`
     *   - `[$obj|$cls, 'method']`          → `"Class::method"`
     *   - non-empty string handler         → passed through verbatim
     *     (typically `"Class::method"` already)
     *   - invokable object (`__invoke`)    → `"Class::__invoke"`
     *   - any other object                 → bare class name
     *   - everything else                  → the literal `"callable"`
     *     (the caller has stuffed something exotic, but the slot
     *     is non-null — there's nothing more useful to say without
     *     guessing)
     */
    private static function describeHandler(mixed $handler): string
    {
        if ($handler instanceof \Closure) {
            return 'Closure';
        }
        if (is_array($handler) && count($handler) === 2) {
            $obj = $handler[0];
            $cls = is_object($obj) ? $obj::class : (is_string($obj) ? $obj : 'callable');
            return $cls . '::' . (string) $handler[1];
        }
        if (is_string($handler) && $handler !== '') {
            return $handler;
        }
        if (is_object($handler)) {
            // Invokables (`__invoke`) are the common dispatcher
            // shape — surface them as `Class::__invoke` so the
            // span name distinguishes them from constructor-only
            // objects of the same class. Non-invokable objects
            // fall back to the bare class name (the caller has
            // probably stuffed something exotic in `handler`).
            return is_callable($handler)
                ? $handler::class . '::__invoke'
                : $handler::class;
        }
        return 'callable';
    }

    /**
     * Default handler-invoker for `serve()`. Calls
     * `$hit['handler']` with `(params, request)` and converts the
     * return value into a `ResponseInterface`. Apps with more
     * exotic handler shapes pass their own invoker.
     */
    private static function defaultHandlerInvoker(array $hit, \Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $handler = $hit['handler'];
        if (!is_callable($handler)) {
            return self::psrProblem(500, 'Handler is not invokable');
        }
        $result = $handler($hit['params'] ?? [], $request);
        if ($result instanceof \Psr\Http\Message\ResponseInterface) {
            return $result;
        }
        return self::arrayToPsrResponse(is_array($result) ? $result : ['data' => $result]);
    }

    /**
     * Serialise a handler's array return as a JSON-envelope PSR-7
     * response. Mirrors the convention-router envelope so existing
     * controllers and the new PSR-7 path produce wire-identical
     * output. `meta.status` (when set) selects the HTTP status;
     * 4xx / 5xx codes get `application/problem+json`, everything
     * else gets `application/json`.
     *
     * @param array<string, mixed> $body
     */
    public static function arrayToPsrResponse(array $body): \Psr\Http\Message\ResponseInterface
    {
        $status      = (int) ($body['meta']['status'] ?? 200);
        $isFailure   = $status >= 400;
        $contentType = $isFailure ? 'application/problem+json' : 'application/json';

        $payload = $isFailure
            ? array_filter([
                'type'   => $body['meta']['type']   ?? 'about:blank',
                'title'  => $body['meta']['title']  ?? '',
                'status' => $status,
                'errors' => $body['meta']['errors'] ?? null,
            ], static fn ($v) => $v !== null)
            : array_filter(
                ['data' => $body['data'] ?? null, 'meta' => $body['meta'] ?? null],
                static fn ($v) => $v !== null,
            );

        return new \Nyholm\Psr7\Response(
            $status,
            ['Content-Type' => $contentType],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Build a Problem Details PSR-7 response. Used by `serve()`
     * for routes that don't match (404 / 405) and exposed for
     * apps that want a quick 4xx without going through
     * `Response::problem` + the convention-router renderer.
     */
    public static function psrProblem(int $status, ?string $title = null): \Psr\Http\Message\ResponseInterface
    {
        return new \Nyholm\Psr7\Response(
            $status,
            ['Content-Type' => 'application/problem+json'],
            json_encode([
                'type'   => 'about:blank',
                'title'  => $title ?? Response::getResponseCodeResult($status),
                'status' => $status,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Convention-router entry point. Resolves the request via
     * `Service\Api::findController`, dispatches to the matching
     * controller's versioned action, renders the JSON envelope
     * with `App::render`. Older apps (and the framework's own
     * `Tests/Http/Controller`-shaped fixtures) live here.
     *
     * For new apps, `serve(Router)` is the recommended shape.
     */
    public function run(): void
    {
        try {
            $response = $this->dispatch();
        } catch (\Throwable $exception) {
            $response = $this->renderFailure($exception);
        }
        self::render($response);
    }

    private function dispatch(): Response
    {
        $request = $this->container->get(Request::class);
        $this->api->request = $request;

        if (!$request->isValidated()) {
            return $this->renderFailure($request->getException());
        }

        $controller_ref = $this->api->findController($request);
        try {
            $this->api->controller = $this->container->get($controller_ref);
        } catch (\Rxn\Framework\Error\ContainerException $e) {
            // Convention router resolved the URL into a class
            // reference, but the class itself doesn't exist (or
            // isn't autoloadable). From the client's perspective
            // that's still "no such resource" — 404, not 500.
            throw new \Rxn\Framework\Error\NotFoundException(
                "No route matches this request",
                404,
                $e,
            );
        }

        return $this->api->controller->trigger();
    }

    private function renderFailure(\Throwable $exception): Response
    {
        $response = $this->container->get(Response::class);
        if ($response->isRendered()) {
            $existing = $response->getFailureResponse();
            if ($existing instanceof Response) {
                return $existing;
            }
        }
        return $response->getFailure($exception);
    }

    /**
     * @throws AppException
     */
    private static function render(Response $response): void
    {
        if (ob_get_contents()) {
            throw new AppException("Output buffer already has content; cannot render");
        }

        $code = $response->getCode() ?: Response::DEFAULT_SUCCESS_CODE;
        http_response_code((int)$code);
        // 304 / 204 are headers-only by spec.
        if ($code === 304 || $code === 204) {
            return;
        }
        // Errors are RFC 7807 Problem Details. The whole ecosystem
        // — API gateways, client libraries, error aggregators —
        // already speaks this shape, and shipping a single format
        // kills a whole class of negotiation bugs. Success stays on
        // the `{data, meta}` envelope because 7807 is errors-only.
        if ($response->isError()) {
            header('content-type: application/problem+json');
            echo json_encode(
                $response->toProblemDetails($_SERVER['REQUEST_URI'] ?? null),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
            return;
        }
        header('content-type: application/json');
        echo $response->toJson();
    }

    public static function getElapsedMs(): string
    {
        $start = defined(__NAMESPACE__ . '\\START') ? constant(__NAMESPACE__ . '\\START') : microtime(true);
        return (string)round((microtime(true) - $start) * 1000, 3) . ' ms';
    }

    public static function hasEnvironmentErrors(): bool
    {
        return !empty(self::$environment_errors);
    }

    /**
     * @throws AppException
     */
    public static function renderEnvironmentErrors(?\Exception $exception = null): void
    {
        if ($exception !== null) {
            self::appendEnvironmentError($exception);
        }
        $response = new Response(null);
        $response->getFailure(new AppException('Environment errors on startup'));
        $response->addMetaField('startup_errors', self::isProductionEnvironment() ? [] : self::$environment_errors);
        self::render($response);
    }

    public static function appendEnvironmentError(\Exception $exception): void
    {
        self::$environment_errors[] = self::isProductionEnvironment()
            ? ['message' => 'Startup error']
            : [
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'message' => $exception->getMessage(),
            ];
    }

    private static function isProductionEnvironment(): bool
    {
        return getenv('ENVIRONMENT') === 'production';
    }
}

