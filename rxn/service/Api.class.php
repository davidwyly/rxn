<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
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

    /**
     * @var Controller $controller
     */
    public $controller;

    /**
     * Api constructor.
     */
    public function __construct()
    {

    }

    /**
     * @param Request $request
     *
     * @return string
     */
    public function findController(Request $request) {
        return $request->getControllerRef();
    }
}