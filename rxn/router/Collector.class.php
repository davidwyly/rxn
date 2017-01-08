<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Router;

use \Rxn\Config;

/**
 * Class Collector
 *
 * @package Rxn\Router
 */
class Collector
{
    /**
     * @var array|null
     */
    public $get;

    /**
     * @var array|null
     */
    public $post;

    /**
     * @var array|null
     */
    public $header;

    const URL_STYLE = 'version/controller/action(/param/value/param2/value2/...)';

    /**
     * Collector constructor.
     *
     * @param Config $config
     */
    public function __construct(Config $config) {
        $this->get = $this->getRequestUrlParams($config);
        $this->post = $this->getRequestDataParams();
        $this->header = $this->getRequestHeaderParams();
    }

    /**
     * @param $paramName
     *
     * @return string
     * @throws \Exception
     */
    public function getUrlParam($paramName) {
        if (!isset($this->get[$paramName])) {
            throw new \Exception("No GET param by the name of '$paramName',500");
        }
        return (string)$this->get[$paramName];
    }

    /**
     * @return array|null
     */
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
        return (array)$_POST;
    }

    /**
     * @param $integer
     *
     * @return bool
     */
    private function isEven($integer) {
        if ($integer == 0) {
            return true;
        }
        if ($integer % 2 == 0) {
            return true;
        }
        return false;
    }

    /**
     * @param $integer
     *
     * @return bool
     */
    private function isOdd($integer) {
        if (!self::isEven($integer)) {
            return true;
        }
        return false;
    }

    /**
     * @param Config $config
     * @param        $params
     *
     * @return array
     */
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

    /**
     * @param Config $config
     *
     * @return array|null
     */
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
        return (array)$_GET;
    }

    /**
     * @return array|null
     */
    public function getRequestHeaderParams() {
        $headerParams = null;
        foreach ($_SERVER as $key=>$value) {
            if (mb_stripos($key,'HTTP_RXN') !== false) {
                $lowerKey = mb_strtolower($key);
                $lowerKey = preg_replace("#http\_#",'',$lowerKey);
                $headerParams[$lowerKey] = $value;
            }
        }
        return (array)$headerParams;
    }

    /**
     * @param Config $config
     *
     * @return mixed
     * @throws \Exception
     */
    public function detectVersion(Config $config) {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['version'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect version from URL; URL style is '$urlStyle'",400);

        }
        return $get['version'];
    }

    /**
     * @param Config $config
     *
     * @return mixed
     * @throws \Exception
     */
    public function detectController(Config $config) {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['controller'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect controller from URL; URL style is '$urlStyle'",400);
        }
        return $get['controller'];
    }

    /**
     * @param Config $config
     *
     * @return mixed
     * @throws \Exception
     */
    public function detectAction(Config $config) {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['action'])) {
            $urlStyle = self::URL_STYLE;
            throw new \Exception("Cannot detect action from URL; URL style is '$urlStyle'",400);
        }
        return $get['action'];
    }
}