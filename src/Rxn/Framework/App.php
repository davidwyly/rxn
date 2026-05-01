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
     * Resolve the request, dispatch to the controller, render the
     * JSON envelope.
     */
    public function run(): void
    {
        try {
            $response = $this->dispatch();
        } catch (\Exception $exception) {
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

    private function renderFailure(\Exception $exception): Response
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
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
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
        $response->addMetaField('startup_errors', self::$environment_errors);
        self::render($response);
    }

    public static function appendEnvironmentError(\Exception $exception): void
    {
        self::$environment_errors[] = [
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
            'message' => $exception->getMessage(),
        ];
    }
}
