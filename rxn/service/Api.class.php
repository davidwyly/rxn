<?php
/**
 * This file is part of the Rxn (Reaction) PHP API Framework
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Service;

use \Rxn\ApplicationService;
use \Rxn\Api\Request;
use \Rxn\Api\Controller;

class Api extends ApplicationService
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