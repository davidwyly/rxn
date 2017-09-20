<?php
/**
 * This file is part of the Rxn (Reaction) PHP API App
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Router;

use \Rxn\Config;
use \Rxn\Service;

class Collector extends Service
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var array|null
     */
    private $get;

    /**
     * @var array|null
     */
    private $post;

    /**
     * @var array|null
     */
    private $header;

    /**
     * @var string
     */
    private $url_style = "version/controller/action(/param/value/param2/value2/...)";

    /**
     * Collector constructor.
     *
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->get    = $this->getRequestUrlParams();
        $this->post   = $this->getRequestDataParams();
        $this->header = $this->getRequestHeaderParams();
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
     * @param        $params
     *
     * @return array
     */
    private function processParams($params)
    {
        // assign version, controller, and action
        $processed_params = [];
        foreach ($this->config->endpoint_parameters as $map_parameter) {
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
                    if (!isset($params[$next_key])) {
                        $processed_params[$paired_key] = null;
                        continue;
                    }
                    $paired_value                  = $params[$next_key];
                    $processed_params[$paired_key] = $paired_value;
                }
            }
        }
        return $processed_params;
    }

    /**
     * @return array|null
     */
    public function getRequestUrlParams()
    {
        if (!isset($_GET) || empty($_GET)) {
            return null;
        }

        if ((isset($_GET['api_version']) && !empty($_GET['api_version']))
            && (isset($_GET['api_endpoint']) && !empty($_GET['api_endpoint']))
        ) {

        }

        if (isset($_GET['params']) && !empty($_GET['params'])) {
            // trim any trailing forward slash
            $params = preg_replace('#\/$#', '', $_GET['params']);

            // split the param string into an array
            $params = explode('/', $params);

            // determine the version, controller, and action from the parameters
            $processed_params = $this->processParams($params);

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
            $header_key_exists = (mb_stripos($key, 'HTTP_RXN') !== false);

            if ($header_key_exists) {
                $lower_key                 = mb_strtolower($key);
                $lower_key                 = preg_replace('#http\_#', '', $lower_key);
                $header_params[$lower_key] = $value;
            }
        }
        return (array)$header_params;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function detectVersion()
    {
        $get = $this->getRequestUrlParams();
        if (!isset($get['version'])) {
            throw new \Exception("Cannot detect version from URL; URL style is '$this->url_style'", 400);

        }
        return $get['version'];
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function detectController()
    {
        $get = $this->getRequestUrlParams();
        if (!isset($get['controller'])) {
            throw new \Exception("Cannot detect controller from URL; URL style is '$this->url_style'", 400);
        }
        return $get['controller'];
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function detectAction()
    {
        $get = $this->getRequestUrlParams();
        if (!isset($get['action'])) {
            throw new \Exception("Cannot detect action from URL; URL style is '$this->url_style'", 400);
        }
        return $get['action'];
    }

    /**
     * @param $parameter
     *
     * @return mixed
     * @throws \Exception
     */
    public function getParamFromGet($parameter)
    {
        if (array_key_exists($parameter, $this->get)) {
            return $this->get['parameter'];
        }
        throw new \Exception("Parameter '$parameter' is not part of the GET request");

    }

    /**
     * @param $parameter
     *
     * @return mixed
     * @throws \Exception
     */
    public function getParamFromPost($parameter)
    {
        if (array_key_exists($parameter, $this->post)) {
            return $this->post['parameter'];
        }
        throw new \Exception("Parameter '$parameter' is not part of the POST request");
    }

    /**
     * @param $parameter
     *
     * @return mixed
     * @throws \Exception
     */
    public function getParamFromHeader($parameter)
    {
        if (array_key_exists($parameter, $this->header)) {
            return $this->header['parameter'];
        }
        throw new \Exception("Parameter '$parameter' is not part of the HEADER request");
    }


    /**
     * @return array|null
     */
    public function getFromGet()
    {
        return $this->get;
    }

    /**
     * @return array|null
     */
    public function getFromPost()
    {
        return $this->post;
    }

    /**
     * @return array|null
     */
    public function getFromHeader()
    {
        return $this->header;
    }
}
