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
     * @var Config
     */
    public $config;

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
     * @var array
     */
    static private $environmentErrors = [];

    /**
     * Application constructor.
     *
     * @param Config   $config
     * @param Database $database
     */
    public function __construct(Config $config, Database $database) {
        $timeStart = microtime(true);
        $this->initialize($config, $database, new Service());
        $this->loadServices($config->useServices);
        $this->finalize($this->registry, $timeStart);
    }

    /**
     * @param Config   $config
     *
     * @param Database $database
     * @param Service  $service
     *
     * @throws \Exception
     */
    private function initialize(Config $config, Database $database, Service $service) {
        $this->config = $config;
        $this->service = $service;
        $this->service->addInstance(Database::class,$database);
        $this->service->addInstance(Config::class,$config);
        $this->registry = $this->service->get(Service\Registry::class);
        date_default_timezone_set($config->timezone);
    }

    /**
     * @param $services
     */
    private function loadServices($services) {
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
            $this->renderEnvironmentErrors(
                [
                    'rxn encountered misconfiguration errors during initialization' => self::$environmentErrors
                ]
            );
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
     * @param $json
     *
     * @return bool
     */
    private function isJson($json) {
        json_decode($json);
        return (json_last_error()===JSON_ERROR_NONE);
    }

    /**
     * @param $errors
     * @internal param $environmentErrors
     */
    private function renderEnvironmentErrors($errors) {
        $response = [
            '_rxn' => [
                'success' => false,
                'code' => 500,
                'result' => 'Internal Server Error',
                'message' => $errors,
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
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => $e->getMessage(),
        ];
    }
}