<?php
/**
 * This file is part of the Rxn (Reaction) PHP API Framework
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn;

use \Rxn\Api\Request;
use \Rxn\Data\Database;
use \Rxn\Utility\Debug;
use \Rxn\Service\Registry;
use \Rxn\Api\Controller\Response;
use \Rxn\Error\ApplicationException;

class Application
{
    /**
     * @var Config $config
     */
    public $config;

    /**
     * @var array $databases
     */
    public $databases;

    /**
     * @var Service\Api $api
     */
    public $api;

    /**
     * @var Service\Auth $auth
     */
    public $auth;

    /**
     * @var Service\Data $data
     */
    public $data;

    /**
     * @var Service\Model $model
     */
    public $model;

    /**
     * @var Service\Registry $registry
     */
    public $registry;

    /**
     * @var Service\Router $router
     */
    public $router;

    /**
     * @var Service\Stats $stats
     */
    public $stats;

    /**
     * @var Service\Utility $utility
     */
    public $utility;

    /**
     * @var Service $service Dependency Injection (DI) container
     */
    public $service;

    /**
     * @var \Exception[]
     */
    static private $environment_errors = [];

    /**
     * Application.class constructor.
     *
     * @param Config      $config
     * @param Datasources $datasources
     * @param Service     $service
     * @param float       $timeStart
     *
     * @throws Error\ServiceException
     */
    public function __construct(Config $config, Datasources $datasources, Service $service, $timeStart)
    {
        Application::validateEnvironment(ROOT, APP_ROOT, $config);
        $this->initialize($config, $datasources, $service);
        $services_to_load = $config->getServices();
        $this->loadServices($services_to_load);
        $this->finalize($this->registry, $config, $timeStart);
    }

    /**
     * @param Config      $config
     * @param Datasources $datasources
     * @param Service     $service
     *
     * @throws Error\ServiceException
     */
    private function initialize(Config $config, Datasources $datasources, Service $service)
    {
        $this->config    = $config;
        $this->service   = $service;
        $this->databases = $this->registerDatabases($config, $datasources);
        $this->service->addInstance(Datasources::class, $datasources);
        $this->service->addInstance(Config::class, $config);
        $this->registry = $this->service->get(Registry::class);
        date_default_timezone_set($config->timezone);
    }

    private function registerDatabases(Config $config, Datasources $datasources)
    {
        $databases = [];
        foreach ($datasources->getDatabases() as $datasource_name => $connectionSettings) {
            $databases[] = new Database($config, $datasources, $datasource_name);
        }
        return $databases;
    }

    /**
     * @param $services
     */
    private function loadServices(array $services)
    {
        foreach ($services as $service_name => $service_class) {
            try {
                $this->{$service_name} = $this->service->get($service_class);
            } catch (\Exception $e) {
                self::appendEnvironmentError($e);
            }
        }
    }

    /**
     * @param Registry $registry
     * @param Config   $config
     * @param          $time_start
     *
     * @throws ApplicationException
     * @throws Error\DebugException
     */
    private function finalize(Registry $registry, Config $config, $time_start)
    {
        $registry->sortClasses();
        $this->stats->stop($time_start);
        if (!empty(self::$environment_errors)) {
            self::renderEnvironmentErrors($config);
        }
    }

    /**
     * Runs the application
     */
    public function run()
    {
        try {
            if (empty($this->api->controller)) {
                throw new ApplicationException("No controller has been associated with the application");
            }
            $response_to_render = $this->getSuccessResponse();
        } catch (\Exception $e) {
            $response_to_render = $this->getFailureResponse($e);
        }
        self::render($response_to_render, $this->config);
        die();
    }

    /**
     * @return Response
     * @throws Error\ServiceException
     */
    private function getSuccessResponse()
    {
        // instantiate request model
        $this->api->request = $this->service->get(Request::class);

        // find the correct controller to use; this is determined from the request
        $controller_ref = $this->api->findController($this->api->request);

        // instantiate the controller
        $this->api->controller = $this->service->get($controller_ref);

        // trigger the controller to build a response
        $response_to_render = $this->api->controller->trigger($this->service);

        // return response
        return $response_to_render;
    }

    /**
     * @param \Exception $e
     *
     * @return Response
     * @throws Error\ServiceException
     */
    private function getFailureResponse(\Exception $e)
    {
        // instantiate request model using the DI service container
        $response = $this->service->get(Response::class);

        // build a response
        if (!$response->isRendered()) {
            $response_to_render = $response->getFailure($e);
        } else {
            // sometimes, the request itself will not validate, so grab that response
            $response_to_render = $response->getFailureResponse();
        }

        // return response
        return $response_to_render;
    }

    /**
     * @param Response    $response
     * @param \Rxn\Config $config
     *
     * @throws ApplicationException
     * @throws Error\DebugException
     */
    static private function render(Response $response, Config $config)
    {
        if (ob_get_contents()) {
            throw new ApplicationException("Output buffer already has content; cannot render");
        }

        // determine response code
        $response_code = $response->getCode();

        // encode the response to JSON
        $json = json_encode((object)$response->stripEmptyParams(), JSON_PRETTY_PRINT);

        // remove null bytes, which can be a gotcha upon decoding
        $json = str_replace('\\u0000', '', $json);

        // if the JSON is invalid, dump the raw response
        if (!self::isJson($json)) {
            Debug::dump($response);
            die();
        }

        // render as JSON
        header('content-type: application/json');
        http_response_code($response_code);
        echo $json;
    }

    /**
     * @return mixed
     */
    static public function getElapsedMs()
    {
        $now       = microtime(true);
        $elapsedMs = round(($now - START) * 1000, 3);
        return (string)$elapsedMs . " ms";
    }

    /**
     * @param $json
     *
     * @return bool
     */
    static private function isJson($json)
    {
        json_decode($json);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * @return bool
     */
    static public function hasEnvironmentErrors()
    {
        if (!empty(self::$environment_errors)) {
            return true;
        }
        return false;
    }

    /**
     * Renders environment errors and dies
     *
     * @param Config $config
     *
     * @throws ApplicationException
     * @throws Error\DebugException
     */
    static public function renderEnvironmentErrors(Config $config)
    {
        try {
            throw new ApplicationException("Environment errors on startup");
        } catch (ApplicationException $e) {
            $response = new Response(null);
            $response->getFailure($e);
        }
        $response->meta['startup_errors'] = self::$environment_errors;
        self::render($response, $config);
    }

    /**
     * @param \Exception $e
     *
     * @internal param $errorFile
     * @internal param $errorLine
     * @internal param $errorMessage
     */
    static public function appendEnvironmentError(\Exception $e)
    {
        self::$environment_errors[] = [
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'message' => $e->getMessage(),
        ];
    }

    /**
     * @param             $root
     * @param             $app_root
     * @param \Rxn\Config $config
     * @throws ApplicationException
     */
    static public function validateEnvironment($root, $app_root, Config $config)
    {
        // validate PHP INI file settings
        $ini_requirements = Config::getPhpIniRequirements();
        foreach ($ini_requirements as $ini_key => $requirement) {
            if (ini_get($ini_key) != $requirement) {
                if (is_bool($requirement)) {
                    $requirement = ($requirement) ? 'On' : 'Off';
                }
                throw new ApplicationException("Rxn requires PHP ini setting '$ini_key' = '$requirement'");
            }
        }

        // validate that file caching can work with the environment
        if ($config->use_file_caching) {
            if (!file_exists("$root/$app_root/data/filecache")) {
                throw new ApplicationException("Rxn requires for folder '$root/$app_root/data/filecache' to exist");
            }
            if (!is_writable("$root/$app_root/data/filecache")) {
                throw new ApplicationException("Rxn requires for folder '$root/$app_root/data/filecache' to be writable");
            }
        }

        // validate that multibyte extensions will work properly
        if (!function_exists('mb_strtolower')
            && isset($ini_requirements['zend.multibyte'])
            && $ini_requirements['zend.multibyte'] === true
        ) {
            throw new ApplicationException("Rxn requires the PHP mbstring extension to be installed/enabled");
        }

        // special apache checks
        if (function_exists('apache_get_modules')
            && !in_array('mod_rewrite', apache_get_modules())
        ) {
            throw new ApplicationException("Rxn requires Apache module 'mod_rewrite' to be enabled");
        }
    }
}