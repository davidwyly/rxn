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
    public $organizationFolder = 'organization';

    /**
     * Defines the root application folder
     * Note: Changing this has not been thoroughly tested!
     *
     * Default value: 'rxn'
     *
     * @var string
     */
    public $appFolder = 'rxn';

    /**
     * Do not edit; this is set by the constructor
     *
     * @var string
     */
    public $root;

    /**
     * Defines the root of the rxn and organization folders relative to this file
     *
     * @var string
     */
    public $relativeRoot = "/../";

    /**
     * Extensions that work with the autoloader
     *
     * @var array
     */
    public $autoloadExtensions = [
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
    public $endpointParameters = [
        "version",
        "controller",
        "action",
    ];

    /**
     * Defines the API response key that has request and response meta data
     *
     * @var string
     */
    public $responseLeaderKey = '_rxn';

    /**
     * Defines default core components on startup
     * Warning: Changing this can have unexpected results!
     *
     * @var array
     */
    static private $coreComponentPaths = [
        'Config'    => 'Config.class.php',
        'Databases' => 'Datasources.class.php',
        'Service'   => 'Service.class.php',
        'Registry'  => 'service/Registry.class.php',
        'Debug'     => 'utility/Debug.class.php',
        'Database'  => 'data/Database.class.php',
    ];

    static private $iniRequirements = [
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
     * Getter for private services array
     *
     * @return array
     */
    static public function getIniRequirements()
    {
        return self::$iniRequirements;
    }

    /**
     * Getter for private services array
     *
     * @return array
     */
    static public function getCoreComponentPaths()
    {
        return self::$coreComponentPaths;
    }

    /**
     * Getter for private services array
     *
     * @return array
     */
    public function getServices()
    {
        return $this->services;
    }

    public function __construct()
    {
        $this->root = realpath(__DIR__ . $this->relativeRoot);
    }
}