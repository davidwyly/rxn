<?php declare(strict_types=1);

namespace Rxn\Framework;

/**
 * Static entry point for Rxn applications.
 *
 *   $router = new Http\Router();
 *   $router->get('/products/{id:int}', [ProductController::class, 'show']);
 *   App::serve($router);
 *
 * Boot-free: no constructor, no Container plumbing, no DB connection.
 * Apps that need DI / config / logging / DB wire those at the
 * composition root and inject them into handlers / middleware. The
 * framework itself doesn't reach for any of those during a request —
 * the request flows through the configured `Http\Router` →
 * `Http\Pipeline` → handler invoker, and back out through
 * `Http\PsrAdapter::emit()`.
 */
final class App
{
    /**
     * PSR-7/15-native entry point.
     *
     *   $router = new Http\Router();
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
     * response. `meta.status` (when set) selects the HTTP status;
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
     * apps that want a quick 4xx without going through a
     * full handler.
     */
    public static function psrProblem(int $status, ?string $title = null): \Psr\Http\Message\ResponseInterface
    {
        return new \Nyholm\Psr7\Response(
            $status,
            ['Content-Type' => 'application/problem+json'],
            json_encode([
                'type'   => 'about:blank',
                'title'  => $title ?? self::statusTitle($status),
                'status' => $status,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * HTTP status code → reason phrase. Covers the full set defined
     * by IANA's HTTP Status Code Registry; falls back to a generic
     * label for unknown codes (so a future 7xx code wouldn't crash
     * the response builder).
     */
    private static function statusTitle(int $status): string
    {
        return match ($status) {
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status',
            208 => 'Already Reported',
            226 => 'IM Used',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Payload Too Large',
            414 => 'URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Range Not Satisfiable',
            417 => 'Expectation Failed',
            421 => 'Misdirected Request',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            424 => 'Failed Dependency',
            426 => 'Upgrade Required',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            508 => 'Loop Detected',
            510 => 'Not Extended',
            511 => 'Network Authentication Required',
            default => $status >= 500 ? 'Server Error' : ($status >= 400 ? 'Client Error' : 'OK'),
        };
    }
}
