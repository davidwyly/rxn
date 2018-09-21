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
     * @param BaseConfig $config
     */
    public function __construct(BaseConfig $config)
    {
        ob_start();
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
        $class_path = $this->getClassPathByClassReference($class_reference, ".php");

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

        $class_short_name = $path_array[count($path_array) - 1];

        if (count($path_array) > 2
            && isset($path_array[0])
            && isset($path_array[1])
        ) {
            $class_root_path = '\\' . $path_array[0] . '\\' . $path_array[1];

            switch ($class_root_path) {

                // check to see if we're in the framework namespace
                case $this->config->rxn_namespace:
                    if ($class_short_name == 'Config'
                        || $class_short_name == 'Datasources'
                    ) {
                        // autoload from config folder
                        $path_array[0] = $this->config->config_folder;
                    } else {
                        // autoload from framework folder
                        $path_array[0] = $this->config->rxn_folder;
                    }
                    unset($path_array[1]);
                    break;

                // check to see if we're in the organization namespace
                case $this->config->product_namespace:
                    // autoload from app folder
                    $path_array[0] = $this->config->app_folder;
                    unset($path_array[1]);
                    break;

                default:
                    // do nothing
            }
        }

        // convert the namespaces into lowercase
        $path_array = array_values($path_array);
        unset($path_array[count($path_array) - 1]);
        foreach ($path_array as $key => $value) {
            $path_array[$key] = mb_strtolower($value);
        }

        // tack the short name of the class back onto the end
        array_push($path_array, $class_short_name);

        // convert back into a string for directory reference
        $class_path = implode('/', $path_array);
        $load_path  = __DIR__ . '/..' . $class_path . $extension;

        // validate the path
        if (!file_exists($load_path)) {
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
     *
     * @throws \Exception
     */
    public function validateEnvironment($root, $app_root)
    {
        $this->validateIni();
        $this->validateFileCaching($root, $app_root);
        $this->validateMultiByte();
        $this->validateApache();
    }

    /**
     * @throws \Exception
     */
    private function validateIni()
    {
        // validate PHP INI file settings
        $ini_requirements = $this->config->getPhpIniRequirements();
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
     * @throws \Exception
     */
    private function validateFileCaching($root, $app_root)
    {
        if ($this->config->use_file_caching) {
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
     * @throws \Exception
     */
    private function validateMultiByte()
    {
        $ini_requirements = $this->config->getPhpIniRequirements();
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
