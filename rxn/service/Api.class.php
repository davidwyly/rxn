<?php

namespace Rxn\Service;

use \Rxn\Config;
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

    public function invokeController(Collector $collector)
    {
        $controllerRef = Controller::getRef($collector);
        $controller = new $controllerRef($collector);
        $this->controller = $controller;
        return $controller;
    }
}