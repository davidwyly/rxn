<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Api;

use \Rxn\Config;
use \Rxn\Service;
use \Rxn\Data\Database;
use \Rxn\Application;
use \Rxn\Router\Collector;
use \Rxn\Api\Controller\Response;
use \Rxn\Service\Api;
use \Rxn\Utility\Debug;

/**
 * Class Controller
 *
 * @package Rxn\Api
 */
class Controller
{
    protected $service;
    /**
     * @var Request
     */
    public $request;

    /**
     * @var string
     */
    public $actionMethod;

    /**
     * @var bool
     */
    public $triggered = false;

    protected $actionTimeStart;

    /**
     * @var
     */
    public $actionTimeElapsed;

    /**
     * @var Response
     */
    public $response;
    static public $endpointParameters;

    /**
     * Controller constructor.
     *
     * @param Request  $request
     * @param Response $response
     */
    public function __construct(Request $request, Response $response, Service $service) {
        $this->service = $service;
        $this->request = $request;
        $this->response = $response;
        $actionName = $request->getActionName();
        $actionVersion = $request->getActionVersion();
        $this->actionMethod = $this->getActionMethod($actionName,$actionVersion);
    }

    /**
     * @return array
     */
    public function trigger() {

        $this->triggered = true;

        // determine the action method on the controller to trigger
        try {
            $actionName = $this->request->getActionName();
            $actionVersion = $this->request->getActionVersion();
            $this->actionMethod = $this->getActionMethod($actionName, $actionVersion);
        } catch (\Exception $e) {
            return $this->response->getFailure($e);
        }

        // trigger the action method on the controller
        try {
            $actionTimeStart = microtime(true);
            $actionResponse = $this->{$this->actionMethod}();
            $this->calculateActionTimeElapsed($actionTimeStart);
            $this->validateActionResponse($actionResponse);
            return $this->response->getSuccess() + $actionResponse;
        } catch (\Exception $e) {
            return $this->response->getFailure($e);
        }
    }

    protected function calculateActionTimeElapsed($startMicroTime) {
        $this->actionTimeElapsed = round(microtime(true) - $startMicroTime,4);
    }

    /**
     * @param $actionName
     * @param $actionVersion
     *
     * @return string
     * @throws \Exception
     */
    private function getActionMethod($actionName, $actionVersion) {
        $reflection = new \ReflectionObject($this);
        $methodName = $actionName . "_" . $actionVersion;
        if (!$reflection->hasMethod($methodName)) {
            $controllerName = $reflection->getName();
            throw new \Exception("Method '$methodName' does not exist on '$controllerName'",400);
        }
        return $methodName;
    }

    /**
     * @param $actionResponse
     *
     * @throws \Exception
     */
    private function validateActionResponse($actionResponse) {
        if (!is_array($actionResponse)) {
            $method = $this->actionMethod;
            throw new \Exception("Controller method '$method' must return an array of key-value as a response",500);
        }
    }
}