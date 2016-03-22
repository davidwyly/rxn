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

namespace Rxn\Service;

use \Rxn\Api\Request;
use \Rxn\Config;
use \Rxn\Router\Collector;
use \Rxn\Api\Controller;
use \Rxn\Api\Controller\Response;
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

    public function loadController(Request $request, Response $response)
    {
        $controllerRef = $request->getControllerRef();
        $controller = new $controllerRef($request,$response);
        $this->controller = $controller;
        return $controller;
    }
}