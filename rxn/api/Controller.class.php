<?php

namespace Rxn\Api;

use \Rxn\Config;
use \Rxn\Application;
use \Rxn\Router\Collector;
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

    public function trigger() {
        $this->triggered = true;
        try {
            $this->actionMethod = $this->getActionMethod($this->actionName, $this->actionVersion);
        } catch (\Exception $e) {
            $this->renderFailure($e);
            return false;
        }
        try {
            $response = $this->{$this->actionMethod}();
            $this->renderSuccess($response);
            return true;
        } catch (\Exception $e) {
            $this->renderFailure($e);
            return false;
        }
    }

    private function stopTimer() {
        $this->timeElapsed = round(microtime(true) - Application::$timeStart,4);
    }

    private function generateResponseLeader($success = true, $code, $message = null, $trace = null) {
        $result = Api::getResponseCodeResult($code);
        $received = $this->collector;
        $elapsed = $this->timeElapsed;
        return [
            '_response' => [
                'success' => $success,
                'code' => $code,
                'result' => $result,
                'message' => $message,
                'trace' => $trace,
                'received' => $received,
                'elapsed' => $elapsed,
            ],
        ];
    }

    private function renderSuccess($response) {
        $this->stopTimer();
        $success = true;
        $code = 200;
        $responseLeader = $this->generateResponseLeader((bool)$success,(int)$code);
        $this->response = $responseLeader + $response;
    }

    private function renderFailure(\Exception $e) {
        $this->stopTimer();
        $success = false;
        $code = $this->getErrorCode($e);
        $message = $e->getMessage();
        $trace = $this->getErrorTrace($e);
        $responseLeader = $this->generateResponseLeader($success,$code,$message,$trace);
        $this->response = $responseLeader;
    }

    private function getErrorCode(\Exception $e) {
        $code = $e->getCode();
        if (empty($code)) {
            $code = '500';
        }
        return $code;
    }

    private function getErrorTrace(\Exception $e) {
        $fullTrace = $e->getTrace();
        $allowedDebugKeys = ['file','line','function','class'];
        $trace = array();
        foreach ($allowedDebugKeys as $allowedKey) {
            foreach ($fullTrace as $traceKey=>$traceGroup) {
                if (isset($traceGroup[$allowedKey])) {
                    $trace[$traceKey][$allowedKey] = $traceGroup[$allowedKey];
                }
            }
        }
        foreach ($trace as $key=>$traceGroup) {
            if (isset($traceGroup['file'])) {
                $regex = '^.+\/';
                $trimmedFile = preg_replace("#$regex#",'',$traceGroup['file']);
                $trace[$key]['file'] = $trimmedFile;
            }
        }
        return $trace;
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
        $fullVersion = $collector->getUrlParam('version');
        $periodPosition = mb_strpos($fullVersion,".");
        $controllerVersion= mb_substr($fullVersion,0,$periodPosition);
        return $controllerVersion;
    }

    static public function getActionVersion(Collector $collector)
    {
        $fullVersion = $collector->getUrlParam('version');
        $periodPosition = mb_strpos($fullVersion,".");
        $actionVersionNumber = mb_substr($fullVersion,$periodPosition + 1);
        $actionVersion = "v$actionVersionNumber";
        return $actionVersion;
    }

    static public function getControllerName(Collector $collector)
    {
        return $collector->getUrlParam('controller');
    }

    static public function getActionName(Collector $collector)
    {
        return $collector->getUrlParam('action');
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