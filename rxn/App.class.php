<?php
/**
 * This file is part of the Rxn (Reaction) PHP API App
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn;

use \Rxn\Api\Request;
use \Rxn\Data\Database;
use \Rxn\Utility\Debug;
use \Rxn\Service\Registry;
use \Rxn\Api\Controller\Response;
use \Rxn\Error\AppException;

class App extends Service
{
    /**
     * @var Config $config
     */
    private $config;

    /**
     * @var Datasources
     */
    private $datasources;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var array $databases
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
     * @var Service\Router $router
     */
    public $router;

    /**
     * @var Service\Stats $stats
     */
    public $stats;

    /**
     * @var Service\Utility $utility
     */
    public $utility;

    /**
     * @var \Exception[]
     */
    static private $environment_errors = [];

    /**
     * Application.class constructor.
     *
     * @param Config      $config
     * @param Datasources $datasources
     * @param Container   $container
     *
     * @throws AppException
     * @throws Error\ContainerException
     * @throws Error\DebugException
     */
    public function __construct(Config $config, Datasources $datasources, Container $container)
    {
        $this->config = $config;
        $this->datasources = $datasources;
        $this->container = $container;
        $this->initialize();
    }

    private function initialize()
    {
        date_default_timezone_set($this->config->timezone);
        $this->databases = $this->registerDatabases();
        $this->container->addInstance(Datasources::class, $this->datasources);
        $this->container->addInstance(Config::class, $this->config);
        $this->registry = $this->container->get(Registry::class, [$this->config]);
        $services_to_load = $this->config->getServices();
        $this->loadServices($services_to_load);
        $this->finalize($this->registry, START);
    }

    private function registerDatabases()
    {
        $databases = [];
        foreach ($this->datasources->getDatabases() as $datasource_name => $connectionSettings) {
            $databases[$datasource_name] = new Database($this->config, $this->datasources, $datasource_name);
        }
        return $databases;
    }

    private function loadServices(array $services)
    {
        foreach ($services as $service_name => $service_class) {
            try {
                $this->{$service_name} = $this->container->get($service_class);
            } catch (\Exception $e) {
                self::appendEnvironmentError($e);
            }
        }
    }

    /**
     * @param Registry $registry
     * @param          $time_start
     *
     * @throws AppException
     * @throws Error\DebugException
     */
    private function finalize(Registry $registry, $time_start)
    {
        $registry->sortClasses();
        $this->stats->stop($time_start);
        if (!empty(self::$environment_errors)) {
            self::renderEnvironmentErrors();
        }
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
        } catch (\Exception $e) {
            $response_to_render = $this->getFailureResponse($e);
        }
        self::render($response_to_render);
        die();
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
     * @param \Exception $e
     *
     * @return Response
     * @throws Error\ContainerException
     */
    private function getFailureResponse(\Exception $e)
    {
        // instantiate request model using the DI container container
        $response = $this->container->get(Response::class);

        // build a response
        if (!$response->isRendered()) {
            $response_to_render = $response->getFailure($e);
        } else {
            // sometimes, the request itself will not validate, so grab that response
            $response_to_render = $response->getFailureResponse();
        }

        // return response
        return $response_to_render;
    }

    /**
     * @param Response $response
     *
     * @throws AppException
     * @throws Error\DebugException
     */
    static private function render(Response $response)
    {
        // error out if output buffer has crap in it
        if (ob_get_contents()) {
            throw new AppException("Output buffer already has content; cannot render");
        }

        $response_code = $response->getCode();
        $json          = json_encode((object)$response->stripEmptyParams(), JSON_PRETTY_PRINT);

        // remove null bytes, which can be a gotcha upon decoding
        $json = str_replace('\\u0000', '', $json);

        // if the JSON is invalid, dump the raw response
        if (!self::isJson($json)) {
            Debug::dump($response);
            die();
        }

        // render as JSON
        header('content-type: application/json');
        http_response_code($response_code);
        echo $json;
    }

    /**
     * @return mixed
     */
    static public function getElapsedMs()
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
    static private function isJson($json)
    {
        json_decode($json);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * @return bool
     */
    static public function hasEnvironmentErrors()
    {
        if (!empty(self::$environment_errors)) {
            return true;
        }
        return false;
    }

    /**
     * Renders environment errors
     *
     * @param \Exception|null $e
     *
     * @throws AppException
     * @throws Error\DebugException
     */
    static public function renderEnvironmentErrors(\Exception $e = null)
    {
        if (!is_null($e)) {
            self::appendEnvironmentError($e);
        }
        try {
            throw new AppException("Environment errors on startup");
        } catch (AppException $e) {
            $response = new Response(null);
            $response->getFailure($e);
        }
        $response->meta['startup_errors'] = self::$environment_errors;
        self::render($response);
    }

    /**
     * @param \Exception $e
     *
     * @internal param $errorFile
     * @internal param $errorLine
     * @internal param $errorMessage
     */
    static public function appendEnvironmentError(\Exception $e)
    {
        self::$environment_errors[] = [
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'message' => $e->getMessage(),
        ];
    }
}