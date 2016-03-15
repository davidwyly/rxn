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

namespace Rxn\Api;

use \Rxn\Config;
use \Rxn\Application;
use \Rxn\Router\Collector;
use \Rxn\Api\Controller\Response;
use \Rxn\Service\Api;
use \Rxn\Utility\Debug;

class Controller
{
    public $collector = '\Collector';
    public $controllerVersion;
    public $controllerName;
    public $actionName;
    public $actionVersion;
    public $actionMethod;
    public $triggered = false;
    public $timeElapsed;
    public $response;
    static public $endpointParameters;

    public function __construct(Collector $collector) {
        $this->validateRequiredParams($collector, Config::$endpointParameters);
        $this->collector = $collector;
        $this->controllerVersion = self::getControllerVersion($collector);
        $this->controllerName = self::getControllerName($collector);
        $this->actionName = self::getActionName($collector);
        $this->actionVersion = self::getActionVersion($collector);
    }

    private function validateRequiredParams(Collector $collector, array $parameters) {
        foreach ($parameters as $parameter) {
            if (!isset($collector->get[$parameter])) {
                throw new \Exception("Required parameter '$parameter' not defined in API request URL",400);
            }
        }
    }

    public function trigger(Response $response) {
        $this->triggered = true;

        // determine the action method on the controller to trigger
        try {
            $this->actionMethod = $this->getActionMethod($this->actionName, $this->actionVersion);
        } catch (\Exception $e) {
            return $response->getFailure($e);
        }

        // trigger the action method on the controller
        try {
            $actionResponse = $this->{$this->actionMethod}();
            return $response->getSuccess() + $actionResponse;
        } catch (\Exception $e) {
            return $response->getFailure($e);
        }
    }

    private function getActionMethod($actionName, $actionVersion) {
        $reflection = new \ReflectionObject($this);
        $methodName = $actionName . "_" . $actionVersion;
        if (!$reflection->hasMethod($methodName)) {
            $controllerName = $reflection->getName();
            throw new \Exception("Method '$methodName' does not exist on '$controllerName'",400);
        }
        return $methodName;

    }

    static public function getControllerVersion(Collector $collector)
    {
        try {
            $fullVersion = $collector->getUrlParam('version');
        } catch (\Exception $e) {
            return null;
        }
        $periodPosition = mb_strpos($fullVersion,".");
        $controllerVersion= mb_substr($fullVersion,0,$periodPosition);
        return $controllerVersion;
    }

    static public function getActionVersion(Collector $collector)
    {
        try {
            $fullVersion = $collector->getUrlParam('version');
        } catch (\Exception $e) {
            return null;
        }
        $periodPosition = mb_strpos($fullVersion,".");
        $actionVersionNumber = mb_substr($fullVersion,$periodPosition + 1);
        $actionVersion = "v$actionVersionNumber";
        return $actionVersion;
    }

    static public function getControllerName(Collector $collector)
    {
        try {
            $controllerName = $collector->getUrlParam('controller');
        } catch (\Exception $e) {
            return null;
        }
        return $controllerName;
    }

    static public function getActionName(Collector $collector)
    {
        try {
            $actionName = $collector->getUrlParam('action');
        } catch (\Exception $e) {
            return null;
        }
        return $actionName;
    }

    static public function getRef(Collector $collector) {
        $controllerName = self::getControllerName($collector);
        $controllerVersion = self::getControllerVersion($collector);
        $processedName = self::stringToUpperCamel($controllerName, "_");
        $controllerRef = Config::$vendorNamespace . "\\Controller\\$controllerVersion\\$processedName";
        return $controllerRef;
    }

    static public function stringToUpperCamel($string, $delimiter = null) {
        if (!empty($delimiter)) {
            if (mb_stripos($string,$delimiter)) {
                $stringArray = explode($delimiter,$string);
                $fragmentArray = array();
                foreach ($stringArray as $stringFragment) {
                    $fragmentArray[] = ucfirst($stringFragment);
                }
                return implode($delimiter,$fragmentArray);
            }
        }
        return ucfirst($string);
    }
}