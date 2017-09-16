<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Router;

use \Rxn\Config;
use \Rxn\Utility\MultiByte;

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
    public function __construct(Config $config)
    {
        $this->get    = $this->getRequestUrlParams($config);
        $this->post   = $this->getRequestDataParams();
        $this->header = $this->getRequestHeaderParams();
    }

    /**
     * @param $paramName
     *
     * @return string
     * @throws \Exception
     */
    public function getUrlParam($paramName)
    {
        if (!isset($this->get[$paramName])) {
            throw new \Exception("No GET param by the name of '$paramName',500");
        }
        return (string)$this->get[$paramName];
    }

    /**
     * @return array|null
     */
    public function getRequestDataParams()
    {
        if (!isset($_POST) || empty($_POST)) {
            return null;
        }
        foreach ($_POST as $key => $value) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
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
    private function isEven($integer)
    {
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
    private function isOdd($integer)
    {
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
    private function processParams(Config $config, $params)
    {
        // assign version, controller, and action
        $processed_params = [];
        foreach ($config->endpoint_parameters as $map_parameter) {
            $processed_params[$map_parameter] = array_shift($params);
        }

        // check to see if there are remaining params
        $param_count = count($params);
        if ($param_count > 0) {

            // split params into key-value pairs
            foreach ($params as $key => $value) {
                if ($this->isEven($key)) {
                    $paired_key = $value;
                    $next_key   = $key + 1;
                    if (isset($params[$next_key])) {
                        $paired_value                  = $params[$next_key];
                        $processed_params[$paired_key] = $paired_value;
                    } else {
                        $processed_params[$paired_key] = null;
                    }

                }
            }
        }
        return $processed_params;
    }

    /**
     * @param Config $config
     *
     * @return array|null
     */
    public function getRequestUrlParams(Config $config)
    {
        if (!isset($_GET) || empty($_GET)) {
            return null;
        }

        if (isset($_GET['api_version'])
            && !empty($_GET['api_version'])
            && isset($_GET['api_endpoint'])
            && !empty($_GET['api_endpoint'])
        ) {

        }

        if (isset($_GET['params']) && !empty($_GET['params'])) {
            // trim any trailing forward slash
            $params = preg_replace('#\/$#', '', $_GET['params']);

            // split the param string into an array
            $params = explode('/', $params);

            // determine the version, controller, and action from the parameters
            $processed_params = $this->processParams($config, $params);

            // tack on the other GET params
            $other_params = $_GET;
            unset($other_params['params']);
            foreach ($other_params as $key => $value) {
                $processed_params[$key] = $value;
            }

            return $processed_params;

        }
        return (array)$_GET;
    }

    /**
     * @return array|null
     */
    public function getRequestHeaderParams()
    {
        $header_params = null;
        foreach ($_SERVER as $key => $value) {
            $header_key_exists = (MultiByte::stripos($key, 'HTTP_RXN') !== false);

            if ($header_key_exists) {
                $lower_key                 = MultiByte::strtolower($key);
                $lower_key                 = preg_replace("#http\_#", '', $lower_key);
                $header_params[$lower_key] = $value;
            }
        }
        return (array)$header_params;
    }

    /**
     * @param Config $config
     *
     * @return mixed
     * @throws \Exception
     */
    public function detectVersion(Config $config)
    {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['version'])) {
            $url_style = self::URL_STYLE;
            throw new \Exception("Cannot detect version from URL; URL style is '$url_style'", 400);

        }
        return $get['version'];
    }

    /**
     * @param Config $config
     *
     * @return mixed
     * @throws \Exception
     */
    public function detectController(Config $config)
    {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['controller'])) {
            $url_style = self::URL_STYLE;
            throw new \Exception("Cannot detect controller from URL; URL style is '$url_style'", 400);
        }
        return $get['controller'];
    }

    /**
     * @param Config $config
     *
     * @return mixed
     * @throws \Exception
     */
    public function detectAction(Config $config)
    {
        $get = $this->getRequestUrlParams($config);
        if (!isset($get['action'])) {
            $url_style = self::URL_STYLE;
            throw new \Exception("Cannot detect action from URL; URL style is '$url_style'", 400);
        }
        return $get['action'];
    }
}