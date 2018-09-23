<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

use \Rxn\Framework\Config;
use \Rxn\Framework\Error\RequestException;

class Request
{
    /**
     * @var Collector
     */
    private $collector;

    /**
     * @var Config
     */
    private $config;

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
    private $url;

    /**
     * Request constructor.
     *
     * @param Collector $collector
     * @param Config    $config
     *
     */
    public function __construct(Collector $collector, Config $config)
    {
        $this->collector = $collector;
        $this->config    = $config;

        // exceptions that appear here may need special handling
        try {
            $this->validateRequiredParams();
        } catch (\Exception $exception) {
            $this->validated = false;
            $this->exception = $exception;
        }

        // assign from collector
        try {
            $this->controller_name    = $this->createControllerName();
            $this->controller_version = $this->parseControllerVersion();
            $this->controller_ref     = $this->createControllerRef($this->controller_name, $this->controller_version);
            $this->action_name        = $this->createActionName();
            $this->action_version     = $this->parseActionVersion();
            $this->url                = (array)$this->getSanitizedUrl();
        } catch (\Exception $exception) {
            $this->validated = true;
            $this->exception = $exception;
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
     * @return Collector
     */
    public function getCollector()
    {
        return $this->collector;
    }

    /**
     * @return array|null
     * @throws RequestException
     */
    private function getSanitizedUrl()
    {
        $get_parameters = $this->collector->getFromGet();
        if (!is_array($get_parameters)) {
            throw new RequestException("Cannot get URL params, verify Apache/Nginx and virtual hosts settings", 510);
        }
        foreach ($get_parameters as $get_parameter_key => $getParameterValue) {
            if (!in_array($get_parameter_key, $this->config->endpoint_parameters)) {
                unset($get_parameters[$get_parameter_key]);
            }
        }
        return $get_parameters;
    }

    /**
     * @return bool
     * @throws RequestException
     * @throws \Exception
     */
    private function validateRequiredParams()
    {
        foreach ($this->config->endpoint_parameters as $parameter) {
            $param_from_get = $this->collector->getParamFromGet($parameter);
            if (!isset($param_from_get)) {
                throw new RequestException("Required parameter $parameter is missing from request");
            }
        }
        $this->validated = true;
        return true;
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
     * @return null|string
     */
    public function parseControllerVersion()
    {
        try {
            $full_version = $this->collector->getParamFromGet('version');
        } catch (\Exception $exception) {
            return null;
        }

        $period_position    = mb_strpos($full_version, ".");
        $controller_version = mb_substr($full_version, 0, $period_position);

        return $controller_version;
    }

    /**
     * @return null|string
     */
    public function parseActionVersion()
    {
        try {
            $full_version = $this->collector->getParamFromGet('version');
        } catch (\Exception $exception) {
            return null;
        }

        $period_position       = mb_strpos($full_version, ".");
        $action_version_number = mb_substr($full_version, $period_position + 1);

        $action_version = "v$action_version_number";
        return $action_version;
    }

    /**
     * @return null|string
     */
    public function createControllerName()
    {
        try {
            $controller_name = $this->collector->getParamFromGet('controller');
        } catch (\Exception $exception) {
            return null;
        }
        return $controller_name;
    }

    /**
     * @return null|string
     */
    public function createActionName()
    {
        try {
            $action_name = $this->collector->getParamFromGet('action');
        } catch (\Exception $exception) {
            return null;
        }
        return $action_name;
    }

    /**
     * @param        $controller_name
     * @param        $controller_version
     *
     * @return string|null
     */
    public function createControllerRef($controller_name, $controller_version)
    {
        if (empty($controller_name)) {
            return null;
        }
        $processed_name = $this->stringToUpperCamel($controller_name, "_");
        $controller_ref = $this->config->product_namespace . "\\Controller\\$controller_version\\$processed_name";
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

            $delimiter_exists = (mb_stripos($string, $delimiter) !== false);

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
