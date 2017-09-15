<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

use \Rxn\Config;
use \Rxn\ApplicationConfig;
use \Rxn\ApplicationDatasources;
use \Rxn\Service\Registry;
use \Rxn\Api\Request;
use \Rxn\Api\Controller\Response;
use \Rxn\Data\Database;
use \Rxn\Utility\Debug;

/**
 * Class Application.class
 *
 * @package Rxn
 */
class Application
{
    /**
     * @var Config $config
     */
    public $config;

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
     * @var Service $service Dependency Injection (DI) container
     */
    public $service;

    /**
     * @var array $environment_errors
     */
    static private $environment_errors = [];

    /**
     * Application.class constructor.
     *
     * @param Config      $config
     * @param Datasources $datasources
     * @param Service     $service
     * @param float       $timeStart
     *
     * @throws \Exception
     */
    public function __construct(Config $config, Datasources $datasources, Service $service, $timeStart)
    {
        $this->initialize($config, $datasources, $service);
        $services_to_load = $config->getServices();
        $this->loadServices($services_to_load);
        $this->finalize($this->registry, $timeStart);
    }

    /**
     * @param Config      $config
     * @param Datasources $datasources
     * @param Service     $service
     *
     * @throws \Exception
     */
    private function initialize(Config $config, Datasources $datasources, Service $service)
    {
        $this->config    = $config;
        $this->service   = $service;
        $this->databases = $this->registerDatabases($config, $datasources);
        $this->service->addInstance(Datasources::class, $datasources);
        $this->service->addInstance(Config::class, $config);
        $this->registry = $this->service->get(Registry::class);
        date_default_timezone_set($config->timezone);
    }

    private function registerDatabases(Config $config, Datasources $datasources)
    {
        $databases = [];
        foreach ($datasources->getDatabases() as $datasource_name => $connectionSettings) {
            $databases[] = new Database($config, $datasources, $datasource_name);
        }
        return $databases;
    }

    /**
     * @param $services
     */
    private function loadServices(array $services)
    {
        foreach ($services as $service_name => $service_class) {
            try {
                $this->{$service_name} = $this->service->get($service_class);
            } catch (\Exception $e) {
                self::appendEnvironmentError($e);
            }
        }
    }

    /**
     * @param Registry $registry
     * @param          $time_start
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
            $response_to_render = $this->getSuccessResponse();
        } catch (\Exception $e) {
            $response_to_render = $this->getFailureResponse($e);
        }
        $this->render($response_to_render, $this->config);
        die();
    }

    /**
     * @return Response
     * @throws \Exception
     */
    private function getSuccessResponse()
    {
        // instantiate request model
        $this->api->request = $this->service->get(Request::class);

        // find the correct controller to use; this is determined from the request
        $controller_ref = $this->api->findController($this->api->request);

        // instantiate the controller
        $this->api->controller = $this->service->get($controller_ref);

        // trigger the controller to build a response
        $response_to_render = $this->api->controller->trigger($this->service);

        // return response
        return $response_to_render;
    }

    /**
     * @param \Exception $e
     *
     * @return Response
     * @throws \Exception
     */
    private function getFailureResponse(\Exception $e)
    {
        // instantiate request model using the DI service container
        $response = $this->service->get(Response::class);

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
     * @param Response    $response
     * @param \Rxn\Config $config
     *
     * @throws \Exception
     */
    private function render(Response $response, Config $config)
    {
        if (ob_get_contents()) {
            die();
        }

        // determine response code
        $response_code = $response->code;

        // encode the response to JSON
        $json = json_encode((object)$response, JSON_PRETTY_PRINT);

        // remove null bytes, which can be a gotcha upon decoding
        $json = str_replace('\\u0000', '', $json);

        // if the JSON is invalid, dump the raw response
        if (!$this->isJson($json)) {
            Debug::dump($response);
            die();
        }

        // render as JSON
        header('content-type: application/json');
        http_response_code($response_code);
        echo($json);
    }

    /**
     * @return mixed
     */
    static public function getElapsedMs()
    {
        $now       = microtime(true);
        $elapsedMs = round(($now - \RXN_START) * 1000, 3);
        return (string)$elapsedMs . " ms";
    }

    /**
     * @param $json
     *
     * @return bool
     */
    private function isJson($json)
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
     * Renders environment errrors and dies
     */
    static public function renderEnvironmentErrors()
    {
        $response = [
            '_rxn' => [
                'success' => false,
                'code'    => 500,
                'result'  => 'Internal Server Error',
                'elapsed' => self::getElapsedMs(),
                'message' => [
                    'environment errors on initialization' => self::$environment_errors,
                ],
            ],
        ];
        http_response_code(500);
        header('content-type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT);
        die();
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

    /**
     * @param $root
     * @param $app_root
     */
    static public function includeCoreComponents($root, $app_root)
    {
        $core_component_paths = ApplicationConfig::getCoreComponentPaths();
        foreach ($core_component_paths as $name => $core_component_path) {
            if (!file_exists("$root/$app_root/$core_component_path")) {
                try {
                    throw new \Exception("Rxn core component '$name' expected at '$core_component_path'");
                } catch (\Exception $e) {
                    self::appendEnvironmentError($e);
                }
            } else {
                /** @noinspection PhpIncludeInspection */
                require_once("$root/$app_root/$core_component_path");
            }
        }
    }

    /**
     * @param        $root
     * @param        $app_root
     * @param Config $config
     */
    static public function validateEnvironment($root, $app_root, Config $config)
    {

        // validate PHP INI file settings
        $ini_requirements = Config::getIniRequirements();
        foreach ($ini_requirements as $ini_key => $requirement) {
            if (ini_get($ini_key) != $requirement) {
                if (is_bool($requirement)) {
                    $requirement = ($requirement) ? 'On' : 'Off';
                }
                try {
                    throw new \Exception("Rxn requires PHP ini setting '$ini_key' = '$requirement'");
                } catch (\Exception $e) {
                    self::appendEnvironmentError($e);
                }
            }
        }

        // validate that file caching is enabled
        if ($config->use_file_caching) {
            if (!file_exists("$root/$app_root/data/filecache")) {
                try {
                    throw new \Exception("Rxn requires for folder '$root/$app_root/data/filecache' to exist");
                } catch (\Exception $e) {
                    self::appendEnvironmentError($e);
                }
            }

            if (!is_writable("$root/$app_root/data/filecache")) {
                try {
                    throw new \Exception("Rxn requires for folder '$root/$app_root/data/filecache' to be writable");
                } catch (\Exception $e) {
                    self::appendEnvironmentError($e);
                }
            }
        }

        if (!function_exists('mb_strtolower')
            && isset($ini_requirements['zend.multibyte'])
            && $ini_requirements['zend.multibyte'] === true
        ) {
            try {
                throw new \Exception("Rxn requires the PHP mbstring extension to be installed/enabled");
            } catch (\Exception $e) {
                self::appendEnvironmentError($e);
            }
        }

        if (function_exists('apache_get_modules')) {
            if (!in_array('mod_rewrite', apache_get_modules())) {
                try {
                    throw new \Exception("Rxn requires Apache module 'mod_rewrite' to be enabled");
                } catch (\Exception $e) {
                    self::appendEnvironmentError($e);
                }
            }
        }

        /**
         * Render errors when finished
         */
        if (self::hasEnvironmentErrors()) {
            self::renderEnvironmentErrors();
        }
    }
}