<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Service;

use \Rxn\Api\Request;
use \Rxn\Api\Controller;

class Api
{

    /**
     * @var Controller $controller
     */
    public $controller;

    /**
     * @var Request
     */
    public $request;

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
    public function findController(Request $request)
    {
        return $request->getControllerRef();
    }
}