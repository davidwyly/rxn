<?php

namespace Rxn\Router;

use \Rxn\Config;

class Collector
{
    public $get;
    public $post;
    public $header;

    const URL_STYLE = 'version/controller/action(/param/value/param2/value2/...)';

    public function __construct() {
        $this->get = $this->getRequestUrlParams();
        $this->post = $this->getRequestDataParams();
        $this->header = $this->getRequestHeaderParams();
    }

    public function getUrlParam($paramName) {
        if (!isset($this->get[$paramName])) {
            throw new \Exception("No GET param by the name of '$paramName'");
        }
        return (string)$this->get[$paramName];
    }

    static public function getRequestDataParams() {
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

    static private function isEven($integer) {
        if ($integer == 0) {
            return true;
        }
        if ($integer % 2 == 0) {
            return true;
        }
        return false;
    }

    static private function isOdd($integer) {
        if (!self::isEven($integer)) {
            return true;
        }
        return false;
    }

    static private function processParams($params) {
        $requiredUrlParameters = Config::$endpointParameters;
        $requiredCount = count($requiredUrlParameters);
        if (count($params) < $requiredCount) {
            $requiredString = implode(",",$requiredUrlParameters);
            throw new \Exception("The following parameters are required: $requiredString",400);
        }

        // assign version, controller, and action
        $processedParams = array();
        foreach (Config::$endpointParameters as $mapParameter) {
            $processedParams[$mapParameter] = array_shift($params);
        }

        // check to see if there are remaining params
        $paramCount = count($params);
        if ($paramCount > 0) {
            if (self::isOdd($paramCount)) {
                throw new \Exception("Odd number of parameter keys and values",400);
            }

            // split params into key-value pairs
            foreach ($params as $key=>$value) {
                if (self::isEven($key)) {
                    $pairedKey = $value;
                    $nextKey = $key + 1;
                    $pairedValue = $params[$nextKey];
                    $processedParams[$pairedKey] = $pairedValue;
                }
            }
        }
        return $processedParams;
    }

    static public function getRequestUrlParams() {
        if (!isset($_GET) || empty($_GET)) {
            return null;
        }
        if (isset($_GET['params']) && !empty($_GET['params'])) {
            // trim any trailing forward slash
            $params = preg_replace('#\/$#','',$_GET['params']);

            // split the param string into an array
            $params = explode('/',$params);

            return self::processParams($params);
        }
        return $_GET;
    }

    static public function getRequestHeaderParams() {
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

    static public function detectVersion() {
        $get = self::getRequestUrlParams();
        if (!isset($get['version'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect version from URL; URL style is '$urlStyle'",400);

        }
        return $get['version'];
    }

    static public function detectController() {
        $get = self::getRequestUrlParams();
        if (!isset($get['controller'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect controller from URL; URL style is '$urlStyle'",400);
        }
        return $get['controller'];
    }

    static public function detectAction() {
        $get = self::getRequestUrlParams();
        if (!isset($get['action'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect action from URL; URL style is '$urlStyle'",400);
        }
        return $get['action'];
    }
}