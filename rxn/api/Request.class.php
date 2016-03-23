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
        $this->get = $this->getSanitizedGet($collector,$config);
        $this->post = $collector->post;
        $this->header = $collector->header;
    }

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
     * @param Config    $config
     *
     * @throws \Exception
     */
    private function validateRequiredParams(Collector $collector, Config $config) {
        foreach ($config->endpointParameters as $parameter) {
            if (!isset($collector->get[$parameter])) {
                throw new \Exception("Required parameter '$parameter' not defined in API request URL",400);
            }
        }
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