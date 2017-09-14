<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
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
    public $action_method;

    /**
     * @var bool
     */
    public $triggered = false;

    /**
     * @var float
     */
    public $action_elapsed_ms;

    /**
     * @var Response
     */
    public $response;

    /**
     * Controller constructor.
     *
     * @param Request  $request
     * @param Response $response
     * @param Service  $service
     *
     * @throws \Exception
     */
    public function __construct(Request $request, Response $response, Service $service)
    {
        $this->request       = $request;
        $this->response      = $response;
        $action_name         = $request->getActionName();
        $action_version      = $request->getActionVersion();
        $this->action_method = $this->getActionMethod($action_name, $action_version);
    }

    /**
     * @param Service $service
     *
     * @return Response
     */
    public function trigger(Service $service)
    {

        $this->triggered = true;

        // determine the action method on the controller to trigger
        try {
            $action_name         = $this->request->getActionName();
            $action_version      = $this->request->getActionVersion();
            $this->action_method = $this->getActionMethod($action_name, $action_version);
        } catch (\Exception $e) {
            return $this->response->getFailure($e);
        }

        // trigger the action method on the controller
        try {
            $action_time_start = microtime(true);
            $action_response   = $this->getActionResponse($service, $this->action_method);
            $this->calculateActionTimeElapsed($action_time_start);
            $this->validateActionResponse($action_response);
            return $this->response->getSuccess() + $action_response;
        } catch (\Exception $e) {
            return $this->response->getFailure($e);
        }
    }

    /**
     * @param Service $service
     * @param         $method
     *
     * @return mixed
     * @throws \Exception
     */
    protected function getActionResponse(Service $service, $method)
    {
        $reflection        = new \ReflectionObject($this);
        $reflection_method = $reflection->getMethod($method);
        $classes_to_inject = $this->getMethodClassesToInject($reflection_method);
        $objects_to_inject = $this->invokeObjectsToInject($service, $classes_to_inject);
        $action_response   = $reflection_method->invokeArgs($this, $objects_to_inject);
        return $action_response;
    }

    /**
     * @param \ReflectionMethod $reflection_method
     *
     * @return array
     */
    protected function getMethodClassesToInject(\ReflectionMethod $reflection_method)
    {
        $parameters        = $reflection_method->getParameters();
        $classes_to_inject = [];
        foreach ($parameters as $parameter) {
            if ($parameter->getClass()) {
                $classes_to_inject[] = $parameter->getClass()->name;
            }
        }
        return $classes_to_inject;
    }

    /**
     * @param Service $service
     * @param array   $classes_to_inject
     *
     * @return array
     * @throws \Exception
     */
    protected function invokeObjectsToInject(Service $service, array $classes_to_inject)
    {
        $objects_to_inject = [];
        foreach ($classes_to_inject as $class_to_inject) {
            $objects_to_inject[] = $service->get($class_to_inject);
        }
        return $objects_to_inject;
    }

    /**
     * @param $start_micro_time
     */
    protected function calculateActionTimeElapsed($start_micro_time)
    {
        $this->action_elapsed_ms = round((microtime(true) - $start_micro_time) * 1000, 4);
    }

    /**
     * @param $action_name
     * @param $action_version
     *
     * @return string
     * @throws \Exception
     */
    private function getActionMethod($action_name, $action_version)
    {
        if (empty($action_name)) {
            throw new \Exception("Action is missing from the request", 400);
        }
        $reflection  = new \ReflectionObject($this);
        $method_name = $action_name . "_" . $action_version;
        if (!$reflection->hasMethod($method_name)) {
            $controller_name = $reflection->getName();
            throw new \Exception("Method '$method_name' does not exist on '$controller_name'", 400);
        }
        return $method_name;
    }

    /**
     * @param $action_response
     *
     * @throws \Exception
     */
    private function validateActionResponse($action_response)
    {
        if (!is_array($action_response)) {
            $method = $this->action_method;
            throw new \Exception("Controller method '$method' must return an array of key-value as a response", 500);
        }
    }
}