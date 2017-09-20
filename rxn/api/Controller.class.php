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

namespace Rxn\Api;

use \Rxn\Data\Database;
use \Rxn\Config;
use \Rxn\Container;
use \Rxn\Api\Controller\Response;

class Controller
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var Container
     */
    protected $container;

    /**
     * @var null|string
     */
    private $action_name;

    /**
     * @var null|string
     */
    private $action_version;

    /**
     * @var string
     */
    private $action_method;

    /**
     * @var bool
     */
    private $triggered = false;

    /**
     * @var float
     */
    private $action_elapsed_ms;


    /**
     * Controller constructor.
     *
     * @param Config    $config
     * @param Request   $request
     * @param Database  $database
     * @param Response  $response
     * @param Container $container
     *
     * @throws \Exception
     */
    public function __construct(
        Config $config,
        Request $request,
        Database $database,
        Response $response,
        Container $container
    ) {
        /**
         * assign dependencies
         */
        $this->config    = $config;
        $this->request   = $request;
        $this->database  = $database;
        $this->response  = $response;
        $this->container = $container;

        /**
         * assign action attributes
         */
        $this->action_name    = $this->request->getActionName();
        $this->action_version = $this->request->getActionVersion();
        $this->action_method  = $this->getActionMethod();
    }

    /**
     * @return Response
     */
    public function trigger()
    {
        $this->triggered = true;

        // trigger the action method on the controller
        try {
            $action_time_start = microtime(true);
            $action_response   = $this->getActionResponse();
            $this->calculateActionTimeElapsed($action_time_start);
            $this->validateActionResponse($action_response);
            return $this->response->getSuccess() + $action_response;
        } catch (\Exception $exception) {
            return $this->response->getFailure($exception);
        }
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    protected function getActionResponse()
    {
        $reflection        = new \ReflectionObject($this);
        $reflection_method = $reflection->getMethod($this->action_method);
        $classes_to_inject = $this->getMethodClassesToInject($reflection_method);
        $objects_to_inject = $this->invokeObjectsToInject($classes_to_inject);
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
     * @param array $classes_to_inject
     *
     * @return array
     * @throws \Exception
     */
    protected function invokeObjectsToInject(array $classes_to_inject)
    {
        $objects_to_inject = [];
        foreach ($classes_to_inject as $class_to_inject) {
            $objects_to_inject[] = $this->container->get($class_to_inject);
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
     * @return string
     * @throws \Exception
     */
    private function getActionMethod()
    {
        if (empty($this->action_name)) {
            throw new \Exception("Action is missing from the request", 400);
        }
        $reflection  = new \ReflectionObject($this);
        $method_name = $this->action_name . "_" . $this->action_version;
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
