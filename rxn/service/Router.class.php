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
use \Rxn\Router\Collector;

class Router extends ApplicationService
{

    /**
     * @var Collector
     */
    public $collector;

    /**
     * Router constructor.
     *
     * @param Collector $collector
     */
    public function __construct(Collector $collector)
    {
        $this->collector = $collector;
    }

    /**
     * @param $paramName
     *
     * @return mixed
     * @throws \Exception
     */
    public function getUrlParam($paramName)
    {
        if (!isset($this->collector->get[$paramName])) {
            throw new \Exception("Missing GET param $paramName", 400);
        }

        return $this->collector->get[$paramName];
    }

    /**
     * @param $paramName
     *
     * @return mixed
     * @throws \Exception
     */
    public function getDataParam($paramName)
    {
        if (!isset($this->collector->post[$paramName])) {
            throw new \Exception("Missing POST param $paramName", 400);
        }

        return $this->collector->post[$paramName];
    }

    /**
     * @param $paramName
     *
     * @return mixed
     * @throws \Exception
     */
    public function getHeaderParam($paramName)
    {
        if (!isset($this->collector->header[$paramName])) {
            throw new \Exception("Missing HEADER param $paramName", 400);
        }

        return $this->collector->header[$paramName];
    }

    /**
     * @return array|null
     */
    public function getUrlParams()
    {
        return $this->collector->get;
    }

    /**
     * @return null
     */
    public function getDataParams()
    {
        return $this->collector->post;
    }

    /**
     * @return null
     */
    public function getHeaderParams()
    {
        return $this->collector->header;
    }
}