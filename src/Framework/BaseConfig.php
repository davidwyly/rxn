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

namespace Rxn\Framework;

class BaseConfig extends Service
{
    /**
     * @var array
     */
    private $php_ini_requirements = [
        'zend.multibyte' => false,
        'display_errors' => true,
    ];

    /**
     * Defines the maximum session lifetime, including the lifetime of cookies, in seconds
     *
     * Default value: '2400' (40 minutes)
     *
     * @var int
     */
    public $session_lifetime = 2400;

    /**
     * Defines the root organization folder, typically your company or organization name
     *
     * Default value: 'organization'
     *
     * @var string
     */
    public $app_folder = 'app';

    /**
     * Defines the root folder for Rxn
     * Note: Changing this has not been thoroughly tested!
     *
     * Default value: 'rxn'
     *
     * @var string
     */
    public $rxn_folder = 'src';

    public $rxn_namespace = '\\Rxn\\Framework';

    public $config_folder = 'config';


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
     * Defines default services to run on startup
     * Warning: Changing this can have unexpected results!
     *
     * @var array
     */
    private $services = [
        'http'     => '\\Rxn\Framework\\Service\\Api',
        'auth'    => '\\Rxn\Framework\\Service\\Auth',
        'data'    => '\\Rxn\Framework\\Service\\Data',
        'model'   => '\\Rxn\Framework\\Service\\Model',
        'router'  => '\\Rxn\Framework\\Service\\Router',
        'stats'   => '\\Rxn\Framework\\Service\\Stats',
        'utility' => '\\Rxn\Framework\\Service\\Utility',
    ];

    /**
     * Sets the filecache directory that needs special read/write permissions
     *
     * @var string
     */
    public $fileCacheDirectory = 'filecache';

    public function __construct()
    {
        $this->root = realpath(__DIR__ . $this->relative_root);
    }

    /**
     * Getter for private $php_ini_requirements array
     *
     * @return array
     */
    public function getPhpIniRequirements()
    {
        return $this->php_ini_requirements;
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
}
