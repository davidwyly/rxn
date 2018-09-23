<?php declare(strict_types=1);

namespace Rxn\Framework\Service;

use \Rxn\Framework\Service;
use \Rxn\Framework\Http\Request;
use \Rxn\Framework\Http\Controller;

class Api extends Service
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
