<?php
/**
 *
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 *
 */

namespace Rxn\Api;

use \Rxn\Config;
use \Rxn\Application;
use \Rxn\Router\Collector;
use \Rxn\Api\Controller\Response;
use \Rxn\Service\Api;
use \Rxn\Utility\Debug;

class Controller
{
    public $request;
    public $actionMethod;
    public $triggered = false;
    public $timeElapsed;
    public $response;
    static public $endpointParameters;

    public function __construct(Request $request, Response $response) {
        $this->request = $request;
        $this->response = $response;
        $actionName = $request->getActionName();
        $actionVersion = $request->getActionVersion();
        $this->actionMethod = $this->getActionMethod($actionName,$actionVersion);
    }

    public function trigger(Response $response) {
        $this->triggered = true;

        // determine the action method on the controller to trigger
        try {
            $actionName = $this->request->getActionName();
            $actionVersion = $this->request->getActionVersion();
            $this->actionMethod = $this->getActionMethod($actionName, $actionVersion);
        } catch (\Exception $e) {
            return $response->getFailure($e);
        }

        // trigger the action method on the controller
        try {
            $actionResponse = $this->{$this->actionMethod}();
            $this->validateActionResponse($actionResponse);
            return $response->getSuccess() + $actionResponse;
        } catch (\Exception $e) {
            return $response->getFailure($e);
        }
    }

    private function getActionMethod($actionName, $actionVersion) {
        $reflection = new \ReflectionObject($this);
        $methodName = $actionName . "_" . $actionVersion;
        if (!$reflection->hasMethod($methodName)) {
            $controllerName = $reflection->getName();
            throw new \Exception("Method '$methodName' does not exist on '$controllerName'",400);
        }
        return $methodName;
    }

    private function validateActionResponse($actionResponse) {
        if (!is_array($actionResponse)) {
            $method = $this->actionMethod;
            throw new \Exception("Controller method '$method' must return an array of key-value as a response",500);
        }
    }
}