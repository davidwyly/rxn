<?php

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

    public function __construct(Collector $collector) {
        $this->collector = $collector;
        $this->controllerVersion = self::getControllerVersion($collector);
        $this->controllerName = self::getControllerName($collector);
        $this->actionName = self::getActionName($collector);
        $this->actionVersion = self::getActionVersion($collector);
    }

    public function initialize(Collector $collector) {

    }

    private function validateParams() {
        if (is_null($this->controllerVersion)
            || is_null($this->actionVersion)) {
                throw new \Exception("Version must be in API request URL");
        }
        if (is_null($this->controllerName)) {
            throw new \Exception("Controller must be in API request URL");
        }
        if (is_null($this->actionName)) {
            throw new \Exception("Controller action must be in API request URL");
        }
    }

    public function trigger() {
        $this->validateParams();
        $this->triggered = true;
        $response = new Response($this->collector);
        try {
            $this->actionMethod = $this->getActionMethod($this->actionName, $this->actionVersion);
        } catch (\Exception $e) {
            $responseLeader = $response->getFailure($e);
            $responseToRender = $responseLeader;
            return $responseToRender;
        }
        try {
            $actionResponse = $this->{$this->actionMethod}();
            $responseLeader = $response->getSuccess();
            $responseToRender = $responseLeader + $actionResponse;
            return $responseToRender;
        } catch (\Exception $e) {
            $responseLeader = $response->getFailure($e);
            $responseToRender = $responseLeader;
            return $responseToRender;
        }
    }

    private function stopTimer() {
        $this->timeElapsed = round(microtime(true) - Application::$timeStart,4);
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