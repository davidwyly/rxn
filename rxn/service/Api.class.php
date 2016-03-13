<?php

namespace Rxn\Service;

use \Rxn\Router\Collector;
use \Rxn\Api\Controller;
use \Rxn\Service\Registry;

class Api
{

    public $controller;


    public function __construct()
    {

    }

    public function registerController($controllerName, $controllerVersion) {
        Registry::registerController($controllerName, $controllerVersion);
    }


    public function getController(Collector $collector)
    {
        $this->validateCollector($collector);
        $controller = $this->invokeController($collector);
        return $controller;
    }

    private function validateCollector($collector) {
        if (!isset($collector->get['controller'])) {
            throw new \Exception("Controller not defined in API request URL");
        }
    }


    private function invokeController(Collector $collector)
    {
        $controllerRef = Controller::getRef($collector);
        $controller = new $controllerRef($collector);
        return $controller;
    }
}