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

namespace Rxn\Router;

use \Rxn\Config;

class Collector
{
    public $get;
    public $post;
    public $header;

    const URL_STYLE = 'version/controller/action(/param/value/param2/value2/...)';

    public function __construct(Config $config) {
        $this->get = $this->getRequestUrlParams($config);
        $this->post = $this->getRequestDataParams();
        $this->header = $this->getRequestHeaderParams();
    }

    public function getUrlParam($paramName) {
        if (!isset($this->get[$paramName])) {
            throw new \Exception("No GET param by the name of '$paramName'");
        }
        return (string)$this->get[$paramName];
    }

    public function getRequestDataParams() {
        if (!isset($_POST) || empty($_POST)) {
            return null;
        }
        foreach ($_POST as $key=>$value) {
            if (is_string($value)) {
                $decoded = json_decode($value,true);
                if ($decoded) {
                    $_POST[$key] = $decoded;
                }
            }
        }
        return $_POST;
    }

    private function isEven($integer) {
        if ($integer == 0) {
            return true;
        }
        if ($integer % 2 == 0) {
            return true;
        }
        return false;
    }

    private function isOdd($integer) {
        if (!self::isEven($integer)) {
            return true;
        }
        return false;
    }

    private function processParams(Config $config, $params) {

        // assign version, controller, and action
        $processedParams = array();
        foreach ($config->endpointParameters as $mapParameter) {
            $processedParams[$mapParameter] = array_shift($params);
        }

        // check to see if there are remaining params
        $paramCount = count($params);
        if ($paramCount > 0) {

            // split params into key-value pairs
            foreach ($params as $key=>$value) {
                if ($this->isEven($key)) {
                    $pairedKey = $value;
                    $nextKey = $key + 1;
                    if (isset($params[$nextKey])) {
                        $pairedValue = $params[$nextKey];
                        $processedParams[$pairedKey] = $pairedValue;
                    } else {
                        $processedParams[$pairedKey] = null;
                    }

                }
            }
        }
        return $processedParams;
    }

    public function getRequestUrlParams(Config $config) {
        if (!isset($_GET) || empty($_GET)) {
            return null;
        }
        if (isset($_GET['params']) && !empty($_GET['params'])) {
            // trim any trailing forward slash
            $params = preg_replace('#\/$#','',$_GET['params']);

            // split the param string into an array
            $params = explode('/',$params);

            return $this->processParams($config, $params);
        }
        return $_GET;
    }

    public function getRequestHeaderParams() {
        $headerParams = null;
        foreach ($_SERVER as $key=>$value) {
            if (mb_stripos($key,'HTTP_RXN') !== false) {
                $lowerKey = mb_strtolower($key);
                $lowerKey = preg_replace("#http\_#",'',$lowerKey);
                $headerParams[$lowerKey] = $value;
            }
        }
        return $headerParams;
    }

    public function detectVersion(Config $config) {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['version'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect version from URL; URL style is '$urlStyle'",400);

        }
        return $get['version'];
    }

    public function detectController(Config $config) {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['controller'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect controller from URL; URL style is '$urlStyle'",400);
        }
        return $get['controller'];
    }

    public function detectAction(Config $config) {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['action'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect action from URL; URL style is '$urlStyle'",400);
        }
        return $get['action'];
    }
}