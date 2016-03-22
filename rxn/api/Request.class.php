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

class Request
{
    protected $controllerVersion;
    protected $controllerName;
    protected $controllerRef;
    protected $actionName;
    protected $actionVersion;

    public function __construct(Collector $collector, Config $config) {
        $this->validateRequiredParams($collector, $config::$endpointParameters);
        $this->controllerName = $this->createControllerName($collector);
        $this->controllerVersion = $this->createControllerVersion($collector);
        $this->controllerRef = $this->createControllerRef($this->controllerName,$this->controllerVersion);
        $this->actionName = $this->createActionName($collector);
        $this->actionVersion = $this->createActionVersion($collector);
        $this->get = $collector->get;
        $this->post = $collector->post;
        $this->header = $collector->header;
    }

    private function validateRequiredParams(Collector $collector, array $parameters) {
        foreach ($parameters as $parameter) {
            if (!isset($collector->get[$parameter])) {
                throw new \Exception("Required parameter '$parameter' not defined in API request URL",400);
            }
        }
    }

    public function getControllerVersion() {
        return $this->controllerVersion;
    }

    public function getControllerName() {
        return $this->controllerName;
    }

    public function getControllerRef() {
        return $this->controllerRef;
    }

    public function getActionName() {
        return $this->actionName;
    }

    public function getActionVersion() {
        return $this->actionVersion;
    }

    public function createControllerVersion(Collector $collector)
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

    public function createActionVersion(Collector $collector)
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

    public function createControllerName(Collector $collector)
    {
        try {
            $controllerName = $collector->getUrlParam('controller');
        } catch (\Exception $e) {
            return null;
        }
        return $controllerName;
    }

    public function createActionName(Collector $collector)
    {
        try {
            $actionName = $collector->getUrlParam('action');
        } catch (\Exception $e) {
            return null;
        }
        return $actionName;
    }

    public function createControllerRef($controllerName,$controllerVersion) {
        $processedName = $this->stringToUpperCamel($controllerName, "_");
        $controllerRef = Config::$vendorNamespace . "\\Controller\\$controllerVersion\\$processedName";
        return $controllerRef;
    }

    public function stringToUpperCamel($string, $delimiter = null) {
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