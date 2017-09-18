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
     * @var array
     */
    public $registered_classes;

    public function __construct(Config $config)
    {
        $this->registerAutoload($config);
    }

    /**
     * @param Config $config
     */
    public function registerAutoload(Config $config)
    {
        spl_autoload_register(function ($class_name) use ($config) {
            $this->load($config, $class_name);
        });
    }

    /**
     * @param Config $config
     * @param        $class_reference
     *
     * @return bool
     * @throws \Exception
     */
    private function load(Config $config, $class_reference)
    {
        $class_path = $this->getClassPathByClassReference($config, $class_reference, ".class.php");

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
     * @param Config $config
     * @param string $class_reference
     * @param string $extension
     *
     * @return string
     * @throws \Exception
     */
    private function getClassPathByClassReference(Config $config, $class_reference, $extension)
    {
        // break the class namespace into an array
        $path_array = explode("\\", $class_reference);

        // remove the root namespace from the array
        $root = mb_strtolower(array_shift($path_array));

        if ($root != $config->framework_folder) {
            if ($root != $config->organization_folder) {
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

    private function registerClass($class_reference)
    {
        $this->registered_classes[] = $class_reference;
    }
}