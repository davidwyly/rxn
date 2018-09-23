<?php

namespace Rxn\Framework;

use \Rxn\Framework\Http\Request;
use \Rxn\Framework\Data\Database;
use \Rxn\Framework\Service\Registry;
use \Rxn\Framework\Http\Response;
use \Rxn\Framework\Error\AppException;

class App extends Service
{
    /**
     * @var Startup
     */
    private $startup;

    /**
     * @var Service\Stats $stats
     */
    public $stats;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var Database[] $databases
     */
    public $databases;

    /**
     * @var Service\Api $api
     */
    public $api;

    /**
     * @var Service\Auth $auth
     */
    public $auth;

    /**
     * @var Service\Data $data
     */
    public $data;

    /**
     * @var Service\Model $model
     */
    public $model;

    /**
     * @var Service\Registry $registry
     */
    public $registry;

    /**
     * @var Service\Utility $utility
     */
    public $utility;

    /**
     * @var \Exception[]
     */
    private static $environment_errors = [];

    /**
     * Application.class constructor.
     *
     * @throws AppException
     * @throws Error\ContainerException
     */
    public function __construct()
    {
        $this->container = new Container();
        $this->initialize();
    }

    private function initialize()
    {
        $this->startup = $this->container->get(Startup::class);
        $this->registry = $this->container->get(Registry::class);
        $this->loadServices();
        $this->finalize($this->registry, START);
    }

    private function loadServices()
    {
        foreach ($this->getEnvDefinedServices() as $service_name => $service_class) {
            try {
                $this->{$service_name} = $this->container->get($service_class);
            } catch (\Exception $exception) {
                self::appendEnvironmentError($exception);
            }
        }
    }

    private function getEnvDefinedServices() {
        $env_defined_services = [];
        foreach ($_ENV as $env_key => $env_value) {
            if ($env_value == "1") {
                preg_match('#(?<=APP_USE_SERVICE_).+$#', $env_key, $matches);
                if (isset($matches[0])) {
                    $env_defined_services[] = 'Rxn\\Framework\\Service\\' . ucfirst(mb_strtolower($matches[0]));
                }
            }
        }
        return $env_defined_services;
    }

    /**
     * @param Registry $registry
     * @param          $time_start
     *
     * @return bool
     * @throws AppException
     */
    private function finalize(Registry $registry, $time_start)
    {
        $registry->sortClasses();
        try {
            $this->stats = $this->container->get(Service\Stats::class);
        } catch (Error\ContainerException $e){
            return true;
        }
        $this->stats->stop($time_start);
        return true;
    }

    /**
     * Runs the application
     */
    public function run()
    {
        try {
            if (empty($this->api->controller)) {
                throw new AppException("No controller has been associated with the application");
            }
            $response_to_render = $this->getSuccessResponse();
        } catch (\Exception $exception) {
            $response_to_render = $this->getFailureResponse($exception);
        }
        self::render($response_to_render);
    }

    /**
     * @return Response
     * @throws Error\ContainerException
     */
    private function getSuccessResponse()
    {
        $this->api->request = $this->container->get(Request::class);

        // find the correct controller to use; this is determined from the request
        $controller_ref        = $this->api->findController($this->api->request);
        $this->api->controller = $this->container->get($controller_ref);

        // trigger the controller to build a response
        $response_to_render = $this->api->controller->trigger($this->container);

        return $response_to_render;
    }

    /**
     * @param \Exception $exception
     *
     * @return Response
     * @throws Error\ContainerException
     *
     */
    private function getFailureResponse(\Exception $exception)
    {
        // instantiate request model using the DI container container
        $response = $this->container->get(Response::class);

        // build a response
        if (!$response->isRendered()) {
            return $response_to_render = $response->getFailure($exception);
        }

        // sometimes, the request itself will not validate, so grab that response
        return $response_to_render = $response->getFailureResponse();
    }

    /**
     * @param Response $response
     *
     * @throws AppException
     */
    private static function render(Response $response)
    {
        // error out if output buffer has crap in it
        if (ob_get_contents()) {
            throw new AppException("Output buffer already has content; cannot render");
        }

        $response_code = $response->getCode();
        $json          = json_encode((object)$response->stripEmptyParams(), JSON_PRETTY_PRINT);

        // remove null bytes, which can be a gotcha upon decoding
        $json = str_replace('\\u0000', '', $json);

        // error out if JSON is invalid
        if (!self::isJson($json)) {
            throw new AppException("Output JSON is invalid");
        }

        // render as JSON
        header('content-type: application/json');
        http_response_code($response_code);
        echo $json;
    }

    /**
     * @return mixed
     */
    public static function getElapsedMs()
    {
        $now       = microtime(true);
        $elapsedMs = round(($now - START) * 1000, 3);
        return (string)$elapsedMs . " ms";
    }

    /**
     * @param $json
     *
     * @return bool
     */
    private static function isJson($json)
    {
        json_decode($json);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * @return bool
     */
    public static function hasEnvironmentErrors()
    {
        if (!empty(self::$environment_errors)) {
            return true;
        }
        return false;
    }

    /**
     * Renders environment errors
     *
     * @param \Exception|null $exception
     *
     * @throws AppException
     */
    public static function renderEnvironmentErrors(\Exception $exception = null)
    {
        if (!is_null($exception)) {
            self::appendEnvironmentError($exception);
        }
        try {
            throw new AppException("Environment errors on startup");
        } catch (AppException $exception) {
            $response = new Response(null);
            $response->getFailure($exception);
        }
        $response->meta['startup_errors'] = self::$environment_errors;
        self::render($response);
    }

    /**
     * @param \Exception $exception
     *
     * @internal param $errorFile
     * @internal param $errorLine
     * @internal param $errorMessage
     */
    public static function appendEnvironmentError(\Exception $exception)
    {
        self::$environment_errors[] = [
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
            'message' => $exception->getMessage(),
        ];
    }
}
