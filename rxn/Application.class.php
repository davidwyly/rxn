<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

use \Rxn\Api\Request;
use \Rxn\Api\Controller\Response;
use \Rxn\Data\Database;
use \Rxn\Utility\Debug;

/**
 * Class Application
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
     * @var array $environmentErrors
     */
    static private $environmentErrors = [];

    /**
     * Application constructor.
     *
     * @param Config   $config
     * @param Datasources $datasources
     * @param Service $service
     * @param float $timeStart
     */
    public function __construct(Config $config, Datasources $datasources, Service $service, $timeStart) {
        $this->initialize($config, $datasources, $service);
        $servicesToLoad = $config->getServices();
        $this->loadServices($servicesToLoad);
        $this->finalize($this->registry, $timeStart);
    }

    /**
     * @param Config   $config
     * @param Datasources $datasources
     * @param Service  $service
     *
     * @throws \Exception
     */
    private function initialize(Config $config, Datasources $datasources, Service $service) {
        $this->config = $config;
        $this->service = $service;
        $this->databases = $this->registerDatabases($config,$datasources);
        $this->service->addInstance(Datasources::class,$datasources);
        $this->service->addInstance(Config::class,$config);
        $this->registry = $this->service->get(Service\Registry::class);
        date_default_timezone_set($config->timezone);
    }

    private function registerDatabases(Config $config, Datasources $datasources) {
        $databases = [];
        foreach ($datasources->databases as $datasourceName=>$connectionSettings) {
            $databases[] = new Database($config,$datasources,$datasourceName);
        }
        return $databases;
    }

    /**
     * @param $services
     */
    private function loadServices(array $services) {
        foreach ($services as $serviceName=>$serviceClass) {
            try {
                $this->{$serviceName} = $this->service->get($serviceClass);
            } catch (\Exception $e) {
                self::appendEnvironmentError($e);
            }
        }
    }

    /**
     * @param Service\Registry $registry
     *
     * @param                  $timeStart
     */
    private function finalize(Service\Registry $registry, $timeStart) {
        $registry->sortClasses();
        $this->stats->stop($timeStart);
        if (!empty(self::$environmentErrors)) {
            self::renderEnvironmentErrors();
        }
    }

    /**
     * Runs the application
     */
    public function run() {
        try {
            $responseToRender = $this->getSuccessResponse();
        } catch (\Exception $e) {
            $responseToRender = $this->getFailureResponse($e);
        }
        $this->render($responseToRender, $this->config);
        die();
    }

    /**
     * @return array
     * @throws \Exception
     */
    private function getSuccessResponse() {
        // instantiate request model
        $this->api->request = $this->service->get(Request::class);

        // find the correct controller to use; this is determined from the request
        $controllerRef = $this->api->findController($this->api->request);

        // instantiate the controller
        $this->api->controller = $this->service->get($controllerRef);

        // trigger the controller to build a response
        $responseToRender = $this->api->controller->trigger($this->service);

        // return response
        return $responseToRender;
    }

    /**
     * @param \Exception $e
     *
     * @return mixed
     * @throws \Exception
     */
    private function getFailureResponse(\Exception $e) {
        // instantiate request model using the DI service container
        $response = $this->service->get(Response::class);

        // build a response
        if (!$response->isRendered()) {
            $responseToRender = $response->getFailure($e);
        } else {
            // sometimes, the request itself will not validate, so grab that response
            $responseToRender = $response->getFailureResponse();
        }

        // return response
        return $responseToRender;
    }

    /**
     * @param        $responseToRender
     * @param Config $config
     */
    private function render($responseToRender, Config $config) {
        if (ob_get_contents()) {
            die();
        }

        // determine response code
        $responseCode = $responseToRender[$config->responseLeaderKey]->code;

        // encode the response to JSON
        $json = json_encode((object)$responseToRender,JSON_PRETTY_PRINT);

        // remove null bytes, which can be a gotcha upon decoding
        $json = str_replace('\\u0000', '', $json);

        // if the JSON is invalid, dump the raw response
        if (!$this->isJson($json)) {
            Debug::dump($responseToRender);
            die();
        }

        // render as JSON
        header('content-type: application/json');
        http_response_code($responseCode);
        echo($json);
    }

    /**
     * @return mixed
     */
    static public function getElapsedMs() {
        $now = microtime(true);
        $elapsedMs = round(
            ($now - \RXN_START) * 1000,
            3
        );
        return (string)$elapsedMs . " ms";
    }

    /**
     * @param $json
     *
     * @return bool
     */
    private function isJson($json) {
        json_decode($json);
        return (json_last_error()===JSON_ERROR_NONE);
    }

    /**
     * @return bool
     */
    static public function hasEnvironmentErrors() {
        if (!empty(self::$environmentErrors)) {
            return true;
        }
        return false;
    }

    /**
     * Renders environment errrors and dies
     */
    static public function renderEnvironmentErrors() {
        $response = [
            '_rxn' => [
                'success' => false,
                'code'    => 500,
                'result'  => 'Internal Server Error',
                'elapsed' => self::getElapsedMs(),
                'message' => [
                    'environment errors on initialization' => self::$environmentErrors
                ],
            ],
        ];
        http_response_code(500);
        header('content-type: application/json');
        echo json_encode($response,JSON_PRETTY_PRINT);
        die();
    }

    /**
     * @param \Exception $e
     * @internal param $errorFile
     * @internal param $errorLine
     * @internal param $errorMessage
     */
    static public function appendEnvironmentError(\Exception $e) {
        self::$environmentErrors[] = [
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'message' => $e->getMessage(),
        ];
    }

    /**
     * @param $root
     * @param $appRoot
     */
    static public function includeCoreComponents($root, $appRoot) {
        $coreComponentPaths = ApplicationConfig::getCoreComponentPaths();
        foreach ($coreComponentPaths as $name=>$coreComponentPath) {
            if (!file_exists("$root/$appRoot/$coreComponentPath")) {
                try {
                    throw new \Exception("Rxn core component '$name' expected at '$coreComponentPath'");
                } catch (\Exception $e) {
                    self::appendEnvironmentError($e);
                }
            } else {
                /** @noinspection PhpIncludeInspection */
                require_once("$root/$appRoot/$coreComponentPath");
            }
        }
    }

    /**
     * @param $root
     * @param $appRoot
     */
    static public function validateEnvironment($root, $appRoot) {

        // validate PHP INI file settings
        $iniRequirements = ApplicationConfig::getIniRequirements();
        foreach ($iniRequirements as $iniKey => $requirement) {
            if (ini_get($iniKey) != $requirement) {
                if (is_bool($requirement)) {
                    $requirement = ($requirement) ? 'On' : 'Off';
                }
                try {
                    throw new \Exception("Rxn requires PHP ini setting '$iniKey' = '$requirement'");
                } catch (\Exception $e) {
                    self::appendEnvironmentError($e);
                }
            }
        }

        if (!file_exists("$root/$appRoot/data/filecache")) {
            try {
                throw new \Exception("Rxn requires for folder '$root/$appRoot/data/filecache' to exist");
            } catch (\Exception $e) {
                self::appendEnvironmentError($e);
            }
        }

        if (!is_writable("$root/$appRoot/data/filecache")) {
            try {
                throw new \Exception("Rxn requires for folder '$root/$appRoot/data/filecache' to be writable");
            } catch (\Exception $e) {
                self::appendEnvironmentError($e);
            }
        }

        if (!function_exists('mb_strtolower')
            && isset($iniRequirements['zend.multibyte'])
            && $iniRequirements['zend.multibyte'] === true) {
                try {
                    throw new \Exception("Rxn requires the PHP mbstring extension to be installed/enabled");
                } catch (\Exception $e) {
                    self::appendEnvironmentError($e);
                }
        }

        if (function_exists('apache_get_modules')) {
            if (!in_array('mod_rewrite',apache_get_modules())) {
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