<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Api;

use \Rxn\Config;
use \Rxn\Application;
use \Rxn\Router\Collector;
use \Rxn\Api\Controller\Response;
use \Rxn\Service\Api;
use \Rxn\Utility\Debug;

/**
 * Class Request
 *
 * @package Rxn\Api
 */
class Request
{
    protected $validated = false;

    /**
     * @var null|string
     */
    protected $controllerVersion;

    /**
     * @var null|string
     */
    protected $controllerName;

    /**
     * @var string
     */
    protected $controllerRef;

    /**
     * @var null|string
     */
    protected $actionName;

    /**
     * @var null|string
     */
    protected $actionVersion;

    /**
     * @var array
     */
    public $get;

    /**
     * @var array
     */
    public $post;

    /**
     * @var array
     */
    public $header;

    /**
     * Request constructor.
     *
     * @param Collector $collector
     * @param Config    $config
     */
    public function __construct(Collector $collector, Config $config) {
        $this->validateRequiredParams($collector,$config);
        $this->controllerName = $this->createControllerName($collector);
        $this->controllerVersion = $this->createControllerVersion($collector);
        $this->controllerRef = $this->createControllerRef($config, $this->controllerName,$this->controllerVersion);
        $this->actionName = $this->createActionName($collector);
        $this->actionVersion = $this->createActionVersion($collector);
        $this->get = (array)$this->getSanitizedGet($collector,$config);
        $this->post = (array)$collector->post;
        $this->header = (array)$collector->header;
    }

    public function collectFromGet($targetKey, $triggerException = true) {
        foreach ($this->get as $key=>$value) {
            if ($targetKey == $key) {
                return $value;
            }
        }
        if ($triggerException) {
            throw new \Exception("Param '$targetKey' is missing from GET request",400);
        }
        return null;
    }

    public function collectFromPost($targetKey, $triggerException = true) {
        foreach ($this->get as $key=>$value) {
            if ($targetKey == $key) {
                return $value;
            }
        }
        if ($triggerException) {
            throw new \Exception("Param '$targetKey' is missing from POST request",400);
        }
        return null;
    }

    public function collectFromHeader($targetKey, $triggerException = true) {
        foreach ($this->get as $key=>$value) {
            if ($targetKey == $key) {
                return $value;
            }
        }
        if ($triggerException) {
            throw new \Exception("Param '$targetKey' is missing from request header",400);
        }
        return null;
    }

    public function collect($targetKey) {
        $value = $this->collectFromGet($targetKey,false);
        if (!empty($value)) {
            return $value;
        }
        $value = $this->collectFromPost($targetKey,false);
        if (!empty($value)) {
            return $value;
        }
        $value = $this->collectFromHeader($targetKey,false);
        if (!empty($value)) {
            return $value;
        }
        throw new \Exception("Param '$targetKey' is missing from request",400);
    }

    public function collectAll() {
        $args = array();
        foreach ($this->get as $key=>$value) {
            $args[$key] = $value;
        }
        foreach ($this->post as $key=>$value) {
            $args[$key] = $value;
        }
        foreach ($this->header as $key=>$value) {
            $args[$key] = $value;
        }
        return $args;
    }

    /**
     * @param Collector $collector
     * @param Config    $config
     *
     * @return array|null
     */
    private function getSanitizedGet(Collector $collector, Config $config) {
        $getParameters = $collector->get;
        foreach ($config->endpointParameters as $endpointParameter) {
            if (isset($getParameters[$endpointParameter])) {
                unset($getParameters[$endpointParameter]);
            }
        }
        return $getParameters;
    }

    /**
     * @param Collector $collector
     * @param Config $config
     * @return bool
     */
    private function validateRequiredParams(Collector $collector, Config $config) {
        foreach ($config->endpointParameters as $parameter) {
            if (!isset($collector->get[$parameter])) {
                return false;
            }
        }
        $this->validated = true;
    }

    /**
     * @return null|string
     */
    public function getControllerVersion() {
        return $this->controllerVersion;
    }

    /**
     * @return null|string
     */
    public function getControllerName() {
        return $this->controllerName;
    }

    /**
     * @return string
     */
    public function getControllerRef() {
        return $this->controllerRef;
    }

    /**
     * @return null|string
     */
    public function getActionName() {
        return $this->actionName;
    }

    /**
     * @return null|string
     */
    public function getActionVersion() {
        return $this->actionVersion;
    }

    /**
     * @param Collector $collector
     *
     * @return null|string
     */
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

    /**
     * @param Collector $collector
     *
     * @return null|string
     */
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

    /**
     * @param Collector $collector
     *
     * @return null|string
     */
    public function createControllerName(Collector $collector)
    {
        try {
            $controllerName = $collector->getUrlParam('controller');
        } catch (\Exception $e) {
            return null;
        }
        return $controllerName;
    }

    /**
     * @param Collector $collector
     *
     * @return null|string
     */
    public function createActionName(Collector $collector)
    {
        try {
            $actionName = $collector->getUrlParam('action');
        } catch (\Exception $e) {
            return null;
        }
        return $actionName;
    }

    /**
     * @param Config $config
     * @param        $controllerName
     * @param        $controllerVersion
     *
     * @return string
     */
    public function createControllerRef(Config $config, $controllerName,$controllerVersion) {
        $processedName = $this->stringToUpperCamel($controllerName, "_");
        $controllerRef = $config->vendorNamespace . "\\Controller\\$controllerVersion\\$processedName";
        return $controllerRef;
    }

    /**
     * @param       $string
     * @param mixed $delimiter
     *
     * @return string
     */
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