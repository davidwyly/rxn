<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Api;

use \Rxn\Service;
use \Rxn\Api\Controller\Response;
use \Rxn\Utility\Debug;

/**
 * Class Controller
 *
 * @package Rxn\Api
 */
class Controller
{
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

    /**
     * @var float
     */
    public $actionElapsedMs;

    /**
     * @var Response
     */
    public $response;

    /**
     * Controller constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param Service $service
     * @throws \Exception
     */
    public function __construct(Request $request, Response $response, Service $service) {
        $this->request = $request;
        $this->response = $response;
        $actionName = $request->getActionName();
        $actionVersion = $request->getActionVersion();
        $this->actionMethod = $this->getActionMethod($actionName,$actionVersion);
    }

    /**
     * @param Service $service
     *
     * @return array
     */
    public function trigger(Service $service) {

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
            $actionResponse = $this->getActionResponse($service, $this->actionMethod);
            $this->calculateActionTimeElapsed($actionTimeStart);
            $this->validateActionResponse($actionResponse);
            return $this->response->getSuccess() + $actionResponse;
        } catch (\Exception $e) {
            return $this->response->getFailure($e);
        }
    }

    /**
     * @param Service $service
     * @param         $method
     *
     * @return mixed
     */
    protected function getActionResponse(Service $service, $method) {
        $reflection = new \ReflectionObject($this);
        $reflectionMethod = $reflection->getMethod($method);
        $classesToInject = $this->getMethodClassesToInject($reflectionMethod);
        $objectsToInject = $this->invokeObjectsToInject($service, $classesToInject);
        $actionResponse = $reflectionMethod->invokeArgs($this,$objectsToInject);
        return $actionResponse;
    }

    /**
     * @param \ReflectionMethod $reflectionMethod
     *
     * @return array
     */
    protected function getMethodClassesToInject(\ReflectionMethod $reflectionMethod) {
        $parameters = $reflectionMethod->getParameters();
        $classesToInject = array();
        foreach ($parameters as $parameter) {
            if ($parameter->getClass()) {
                $classesToInject[] = $parameter->getClass()->name;
            }
        }
        return $classesToInject;
    }

    /**
     * @param Service $service
     * @param array   $classesToInject
     *
     * @return array
     * @throws \Exception
     */
    protected function invokeObjectsToInject(Service $service, array $classesToInject) {
        $objectsToInject = array();
        foreach ($classesToInject as $classToInject) {
            $objectsToInject[] = $service->get($classToInject);
        }
        return $objectsToInject;
    }

    /**
     * @param $startMicroTime
     */
    protected function calculateActionTimeElapsed($startMicroTime) {
        $this->actionElapsedMs = round((microtime(true) - $startMicroTime) * 1000,4);
    }

    /**
     * @param $actionName
     * @param $actionVersion
     *
     * @return string
     * @throws \Exception
     */
    private function getActionMethod($actionName, $actionVersion) {
        if (empty($actionName)) {
            throw new \Exception("Action is missing from the request",400);
        }
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