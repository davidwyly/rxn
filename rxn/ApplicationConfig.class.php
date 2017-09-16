<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

/**
 * Class BaseConfig
 *
 * @package Rxn
 */
abstract class ApplicationConfig
{
    /**
     * Defines the root organization folder, typically your company or organization name
     *
     * Default value: 'organization'
     *
     * @var string
     */
    public $organization_folder = 'organization';

    /**
     * Defines the root application folder
     * Note: Changing this has not been thoroughly tested!
     *
     * Default value: 'rxn'
     *
     * @var string
     */
    public $app_folder = 'rxn';


    /**
     * Do not edit; this is set by the constructor
     *
     * @var string
     */
    protected $root;

    /**
     * Defines the root of the rxn and organization folders relative to this file
     *
     * @var string
     */
    public $relative_root = "/../";

    /**
     * Enable or disable file caching of objects
     *
     * Default value: false
     *
     * @var bool
     */
    public $use_file_caching = false;

    /**
     * Extensions that work with the autoloader
     *
     * @var array
     */
    public $autoload_extensions = [
        '.class.php',
        '.php',
        '.controller.php',
        '.model.php',
        '.record.php',
        '.interface.php',
    ];

    /**
     * Defines default keys for URL params and routing
     *
     * @var array
     */
    public $endpoint_parameters = [
        "version",
        "controller",
        "action",
    ];

    /**
     * Defines the API response key that has request and response meta data
     *
     * @var string
     */
    public $response_leader_key = '_rxn';

    /**
     * Use a valid \DateTime timezone
     *
     * Default value: 'America/Denver'
     *
     * @var string
     */
    public $timezone = 'America/Denver';

    /**
     * Defines default core components on startup
     * Warning: Changing this can have unexpected results!
     *
     * @var array
     */
    static private $core_component_paths = [
        'MultiByte' => 'utility/MultiByte.class.php',
        'Config'    => 'Config.class.php',
        'Databases' => 'Datasources.class.php',
        'Service'   => 'Service.class.php',
        'Registry'  => 'service/Registry.class.php',
        'Debug'     => 'utility/Debug.class.php',
        'Database'  => 'data/Database.class.php',
        'Query'     => 'data/Query.class.php',
        'Collector' => 'router/Collector.class.php',
        'Request'   => 'api/Request.class.php',
        'Response'  => 'api/controller/Response.class.php',
    ];

    /**
     * Defines default core directories on startup
     * Warning: Changing this can have unexpected results!
     *
     * @var array
     */
    static private $core_component_directories = [
        'error',
    ];

    static private $php_ini_requirements = [
        'zend.multibyte' => false,
        'display_errors' => true,
    ];

    /**
     * Defines default services to run on startup
     * Warning: Changing this can have unexpected results!
     *
     * @var array
     */
    private $services = [
        'api'     => '\\Rxn\\Service\\Api',
        'auth'    => '\\Rxn\\Service\\Auth',
        'data'    => '\\Rxn\\Service\\Data',
        'model'   => '\\Rxn\\Service\\Model',
        'router'  => '\\Rxn\\Service\\Router',
        'stats'   => '\\Rxn\\Service\\Stats',
        'utility' => '\\Rxn\\Service\\Utility',
    ];

    /**
     * Sets the filecache directory that needs special read/write permissions
     *
     * @var string
     */
    public $fileCacheDirectory = 'filecache';

    /**
     * Getter for static private $php_ini_requirements array
     *
     * @return array
     */
    static public function getPhpIniRequirements()
    {
        return self::$php_ini_requirements;
    }

    /**
     * Getter for static private $core_component_paths array
     *
     * @return array
     */
    static public function getCoreComponentPaths()
    {
        return self::$core_component_paths;
    }

    /**
     * Getter for static private $core_component_directories array
     *
     * @return array
     */
    static public function getCoreComponentDirectories()
    {
        return self::$core_component_directories;
    }

    /**
     * Getter for private $services array
     *
     * @return array
     */
    public function getServices()
    {
        return $this->services;
    }

    public function __construct()
    {
        $this->root = realpath(__DIR__ . $this->relative_root);
    }
}