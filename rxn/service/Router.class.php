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

class Router
{
    public $collector;

    public function __construct()
    {
        $this->session = new \Rxn\Router\Session();
        $this->collector = new \Rxn\Router\Collector();
    }

    public function getUrlParam($paramName)
    {
        if (!isset($this->collector->get[$paramName])) {
            throw new \Exception("Missing GET param $paramName", 400);
        }

        return $this->collector->get[$paramName];
    }

    public function getDataParam($paramName)
    {
        if (!isset($this->collector->post[$paramName])) {
            throw new \Exception("Missing POST param $paramName", 400);
        }

        return $this->collector->post[$paramName];
    }

    public function getHeaderParam($paramName)
    {
        if (!isset($this->collector->header[$paramName])) {
            throw new \Exception("Missing HEADER param $paramName", 400);
        }

        return $this->collector->header[$paramName];
    }

    public function getUrlParams()
    {
        return $this->collector->get;
    }

    public function getDataParams()
    {
        return $this->collector->post;
    }

    public function getHeaderParams()
    {
        return $this->collector->header;
    }
}