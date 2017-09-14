<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Api;

use \Rxn\Config;
use \Rxn\Router\Collector;
use \Rxn\Utility\Debug;

/**
 * Class Request
 *
 * @package Rxn\Api
 */
class Request
{
    /**
     * @var bool
     */
    protected $validated = true;

    /**
     * @var \Exception
     */
    protected $exception;

    /**
     * @var null|string
     */
    protected $controller_version;

    /**
     * @var null|string
     */
    protected $controller_name;

    /**
     * @var string
     */
    protected $controller_ref;

    /**
     * @var null|string
     */
    protected $action_name;

    /**
     * @var null|string
     */
    protected $action_version;

    /**
     * @var array
     */
    public $url;

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
     *
     * @throws \Exception
     */
    public function __construct(Collector $collector, Config $config)
    {

        // exceptions that appear here may need special handling
        try {
            $this->validateRequiredParams($collector, $config);
        } catch (\Exception $e) {
            $this->validated = false;
            $this->exception = $e;
        }

        // assign from collector
        try {
            $this->controller_name    = $this->createControllerName($collector);
            $this->controller_version = $this->createControllerVersion($collector);
            $this->controller_ref     = $this->createControllerRef($config, $this->controller_name,
                $this->controller_version);
            $this->action_name        = $this->createActionName($collector);
            $this->action_version     = $this->createActionVersion($collector);
            $this->url                = (array)$this->getSanitizedUrl($collector, $config);
            $this->get                = (array)$this->getSanitizedGet($collector, $config);
            $this->post               = (array)$collector->post;
            $this->header             = (array)$collector->header;
        } catch (\Exception $e) {
            $this->validated = true;
            $this->exception = $e;
        }
    }

    /**
     * @return bool
     */
    public function isValidated()
    {
        return $this->validated;
    }

    /**
     * @return \Exception
     */
    public function getException()
    {
        return $this->exception;
    }

    /**
     * @param      $target_key
     * @param bool $trigger_exception
     *
     * @return mixed|null
     * @throws \Exception
     */
    public function collectFromGet($target_key, $trigger_exception = true)
    {
        foreach ($this->get as $key => $value) {
            if ($target_key == $key) {
                return $value;
            }
        }
        if ($trigger_exception) {
            throw new \Exception("Param '$target_key' is missing from GET request", 400);
        }
        return null;
    }

    /**
     * @param      $targetKey
     * @param bool $triggerException
     *
     * @return mixed|null
     * @throws \Exception
     */
    public function collectFromPost($targetKey, $triggerException = true)
    {
        foreach ($this->get as $key => $value) {
            if ($targetKey == $key) {
                return $value;
            }
        }
        if ($triggerException) {
            throw new \Exception("Param '$targetKey' is missing from POST request", 400);
        }
        return null;
    }

    /**
     * @param      $targetKey
     * @param bool $triggerException
     *
     * @return mixed|null
     * @throws \Exception
     */
    public function collectFromHeader($targetKey, $triggerException = true)
    {
        foreach ($this->get as $key => $value) {
            if ($targetKey == $key) {
                return $value;
            }
        }
        if ($triggerException) {
            throw new \Exception("Param '$targetKey' is missing from request header", 400);
        }
        return null;
    }

    /**
     * @param $targetKey
     *
     * @return mixed|null
     * @throws \Exception
     */
    public function collect($targetKey)
    {
        $value = $this->collectFromGet($targetKey, false);
        if (!empty($value)) {
            return $value;
        }
        $value = $this->collectFromPost($targetKey, false);
        if (!empty($value)) {
            return $value;
        }
        $value = $this->collectFromHeader($targetKey, false);
        if (!empty($value)) {
            return $value;
        }
        throw new \Exception("Param '$targetKey' is missing from request", 400);
    }

    /**
     * @return array
     */
    public function collectAll()
    {
        $args = [];
        foreach ($this->get as $key => $value) {
            $args[$key] = $value;
        }
        foreach ($this->post as $key => $value) {
            $args[$key] = $value;
        }
        foreach ($this->header as $key => $value) {
            $args[$key] = $value;
        }
        return $args;
    }

    /**
     * @param Collector $collector
     * @param Config    $config
     *
     * @return array|null
     * @throws \Exception
     */
    private function getSanitizedUrl(Collector $collector, Config $config)
    {
        $get_parameters = $collector->get;
        if (!is_array($get_parameters)) {
            throw new \Exception("Cannot get sanitized URL, verify virtual hosts");
        }
        foreach ($get_parameters as $get_parameter_key => $getParameterValue) {
            if (!in_array($get_parameter_key, $config->endpoint_parameters)) {
                unset($get_parameters[$get_parameter_key]);
            }
        }
        return $get_parameters;
    }

    /**
     * @param Collector $collector
     * @param Config    $config
     *
     * @return array|null
     */
    private function getSanitizedGet(Collector $collector, Config $config)
    {
        $get_parameters = $collector->get;
        foreach ($config->endpoint_parameters as $endpoint_parameter) {
            if (isset($get_parameters[$endpoint_parameter])) {
                unset($get_parameters[$endpoint_parameter]);
            }
        }
        return $get_parameters;
    }

    /**
     * @param Collector $collector
     * @param Config    $config
     *
     * @return bool
     * @throws \Exception
     */
    private function validateRequiredParams(Collector $collector, Config $config)
    {
        foreach ($config->endpoint_parameters as $parameter) {
            if (!isset($collector->get[$parameter])) {
                throw new \Exception("Required parameter $parameter is missing from request", 400);
            }
        }
        $this->validated = true;
    }

    /**
     * @return null|string
     */
    public function getControllerVersion()
    {
        return $this->controller_version;
    }

    /**
     * @return null|string
     */
    public function getControllerName()
    {
        return $this->controller_name;
    }

    /**
     * @return string
     */
    public function getControllerRef()
    {
        return $this->controller_ref;
    }

    /**
     * @return null|string
     */
    public function getActionName()
    {
        return $this->action_name;
    }

    /**
     * @return null|string
     */
    public function getActionVersion()
    {
        return $this->action_version;
    }

    /**
     * @param Collector $collector
     *
     * @return null|string
     */
    public function createControllerVersion(Collector $collector)
    {
        try {
            $full_version = $collector->getUrlParam('version');
        } catch (\Exception $e) {
            return null;
        }

        if (function_exists('mb_strpos')
            && function_exists('mb_substr')
        ) {
            $period_position    = mb_strpos($full_version, ".");
            $controller_version = mb_substr($full_version, 0, $period_position);
        } else {
            $period_position    = strpos($full_version, ".");
            $controller_version = substr($full_version, 0, $period_position);
        }

        return $controller_version;
    }

    /**
     * @param Collector $collector
     *
     * @return null|string
     */
    public function createActionVersion(Collector $collector)
    {
        try {
            $full_version = $collector->getUrlParam('version');
        } catch (\Exception $e) {
            return null;
        }

        if (function_exists('mb_strpos')
            && function_exists('mb_substr')
        ) {
            $period_position       = mb_strpos($full_version, ".");
            $action_version_number = mb_substr($full_version, $period_position + 1);
        } else {
            $period_position       = strpos($full_version, ".");
            $action_version_number = substr($full_version, $period_position + 1);
        }

        $action_version = "v$action_version_number";
        return $action_version;
    }

    /**
     * @param Collector $collector
     *
     * @return null|string
     */
    public function createControllerName(Collector $collector)
    {
        try {
            $controller_name = $collector->getUrlParam('controller');
        } catch (\Exception $e) {
            return null;
        }
        return $controller_name;
    }

    /**
     * @param Collector $collector
     *
     * @return null|string
     */
    public function createActionName(Collector $collector)
    {
        try {
            $action_name = $collector->getUrlParam('action');
        } catch (\Exception $e) {
            return null;
        }
        return $action_name;
    }

    /**
     * @param Config $config
     * @param        $controller_name
     * @param        $controller_version
     *
     * @return string
     */
    public function createControllerRef(Config $config, $controller_name, $controller_version)
    {
        $processed_name = $this->stringToUpperCamel($controller_name, "_");
        $controller_ref = $config->product_namespace . "\\Controller\\$controller_version\\$processed_name";
        return $controller_ref;
    }

    /**
     * @param string $string
     * @param mixed  $delimiter
     *
     * @return string
     */
    public function stringToUpperCamel($string, $delimiter = null)
    {
        if (!empty($delimiter)) {

            if (function_exists('mb_stripos')) {
                $delimiter_exists = (mb_stripos($string, $delimiter) !== false);
            } else {
                $delimiter_exists = (stripos($string, $delimiter) !== false);
            }

            if ($delimiter_exists) {
                $string_array   = explode($delimiter, $string);
                $fragment_array = [];
                foreach ($string_array as $string_fragment) {
                    $fragment_array[] = ucfirst($string_fragment);
                }
                return implode($delimiter, $fragment_array);
            }
        }
        return ucfirst($string);
    }
}