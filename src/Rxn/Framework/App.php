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
    /** @var Container */
    private $container;

    /** @var Api */
    public $api;

    /** @var Service\Stats|null */
    public $stats;

    /** @var \Exception[] */
    private static $environment_errors = [];

    public function __construct()
    {
        $this->container = new Container();
        $this->container->get(Startup::class);
        $this->container->get(Service\Registry::class);
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

        $controller_ref        = $this->api->findController($request);
        $this->api->controller = $this->container->get($controller_ref);

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
        $json = json_encode(
            (object)$response->stripEmptyParams(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        // Null bytes can trip JSON decoders on the other end.
        $json = str_replace('\\u0000', '', $json);

        header('content-type: application/json');
        http_response_code((int)$code);
        echo $json;
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
        $response->meta['startup_errors'] = self::$environment_errors;
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
