<?php declare(strict_types=1);

namespace Rxn\Framework\Service;

use \Rxn\Framework\Service as BaseService;
use \Rxn\Framework\Http\Request;
use \Rxn\Framework\Http\Controller;

class Api extends BaseService
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
     * @param Request $request
     *
     * @return string
     */
    public function findController(Request $request)
    {
        return $request->getControllerRef();
    }
}
