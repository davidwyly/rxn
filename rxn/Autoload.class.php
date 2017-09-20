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

namespace Rxn;

class Autoload extends Service
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var array
     */
    public $registered_classes;

    /**
     * Autoload constructor.
     *
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        spl_autoload_register(function ($class_reference) {
            $this->load($class_reference);
        });
    }

    /**
     * @param        $class_reference
     *
     * @return bool
     * @throws \Exception
     */
    private function load($class_reference)
    {
        $class_path = $this->getClassPathByClassReference($class_reference, ".class.php");

        if (!isset($class_path)) {
            return false;
        }

        // load the class
        /** @noinspection PhpIncludeInspection */
        include($class_path);

        // register the class
        $this->registerClass($class_reference);

        return true;
    }

    /**
     * @param string $class_reference
     * @param string $extension
     *
     * @return string
     * @throws \Exception
     */
    private function getClassPathByClassReference($class_reference, $extension)
    {
        // break the class namespace into an array
        $path_array = explode("\\", $class_reference);

        // remove the root namespace from the array
        $root = mb_strtolower(array_shift($path_array));

        if ($root != $this->config->framework_folder) {
            if ($root != $this->config->organization_folder) {
                throw new \Exception("Root path '$root' in reference '$class_reference' not defined in config");
            }
        }

        // determine the name of the class without the namespace
        $class_short_name = array_pop($path_array);

        // convert the namespaces into lowercase
        foreach ($path_array as $key => $value) {
            $path_array[$key] = mb_strtolower($value);
        }

        // tack the short name of the class back onto the end
        array_push($path_array, $class_short_name);

        // convert back into a string for directory reference
        $class_path      = implode("/", $path_array);
        $load_path_root  = realpath(__DIR__ . "/../");
        $load_path_class = "/" . $root . "/" . $class_path . $extension;
        $load_path       = $load_path_root . $load_path_class;
        $real_load_path  = realpath($load_path);

        // validate the path
        if (!file_exists($real_load_path)) {
            throw new \Exception("Cannot autoload path '$load_path'");
        }
        return $load_path;
    }

    /**
     * @param $class_reference
     */
    private function registerClass($class_reference)
    {
        $this->registered_classes[] = $class_reference;
    }

    /**
     * @param        $root
     * @param        $app_root
     * @param Config $config
     *
     * @throws \Exception
     */
    public function validateEnvironment($root, $app_root, Config $config)
    {
        $this->validateIni($config);
        $this->validateFileCaching($root, $app_root, $config);
        $this->validateMultiByte($config);
        $this->validateApache();
    }

    /**
     * @param Config $config
     *
     * @throws \Exception
     */
    private function validateIni(Config $config)
    {
        // validate PHP INI file settings
        $ini_requirements = $config->getPhpIniRequirements();
        foreach ($ini_requirements as $ini_key => $requirement) {
            if (ini_get($ini_key) != $requirement) {
                if (is_bool($requirement)) {
                    $requirement = ($requirement) ? 'On' : 'Off';
                }
                throw new \Exception("Rxn requires PHP ini setting '$ini_key' = '$requirement'");
            }
        }
    }

    /**
     * validate that file caching can work with the environment
     *
     * @param Config $config
     *
     * @throws \Exception
     */
    private function validateFileCaching($root, $app_root, Config $config)
    {
        if ($config->use_file_caching) {
            if (!file_exists("$root/$app_root/data/filecache")) {
                throw new \Exception("Rxn requires for folder '$root/$app_root/data/filecache' to exist");
            }
            if (!is_writable("$root/$app_root/data/filecache")) {
                throw new \Exception("Rxn requires for folder '$root/$app_root/data/filecache' to be writable");
            }
        }
    }

    /**
     * validate that multibyte extensions will work properly
     *
     * @param Config $config
     *
     * @throws \Exception
     */
    private function validateMultiByte(Config $config)
    {
        $ini_requirements = $config->getPhpIniRequirements();
        if (!function_exists('mb_strtolower')
            && (isset($ini_requirements['zend.multibyte'])
                && $ini_requirements['zend.multibyte'] !== true)
        ) {
            throw new \Exception("Rxn requires the PHP mbstring extension to be installed/enabled");
        }
    }

    /**
     * special apache checks
     *
     * @throws \Exception
     */
    private function validateApache()
    {
        if (function_exists('apache_get_modules')
            && !in_array('mod_rewrite', apache_get_modules())
        ) {
            throw new \Exception("Rxn requires Apache module 'mod_rewrite' to be enabled");
        }
    }
}