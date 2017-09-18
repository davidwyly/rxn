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

/**
 * Definitions
 */
define(__NAMESPACE__ . '\START', microtime(true));
define(__NAMESPACE__ . '\BASE_ROOT', __DIR__);
define(__NAMESPACE__ . '\APP_ROOT', 'rxn');
define(__NAMESPACE__ . '\ROOT', BASE_ROOT . "/" . APP_ROOT . "/");

/**
 * Begin output buffering
 */
ob_start();

/**
 * Require core components an validate
 */
require_once(ROOT . "/Service.class.php");
require_once(ROOT . "/BaseConfig.class.php");
require_once(ROOT . "/Config.class.php");
registerAutoload(new Config());

/**
 * @param Config $config
 */
function registerAutoload(Config $config)
{
    spl_autoload_register(function ($class_name) use ($config) {
        load($config, $class_name);
    });
}

/**
 * @param Config $config
 * @param        $class_reference
 *
 * @return bool
 * @throws \Exception
 */
function load(Config $config, $class_reference)
{
    $class_path = getClassPathByClassReference($config, $class_reference, ".class.php");

    if (!isset($class_path)) {
        return false;
    }

    // load the class
    /** @noinspection PhpIncludeInspection */
    include($class_path);

    // register the class
    //registerClass($class_reference);

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
function getClassPathByClassReference(Config $config, $class_reference, $extension)
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
    $load_path_root  = realpath(__DIR__);
    $load_path_class = "/" . $root . "/" . $class_path . $extension;
    $load_path       = $load_path_root . $load_path_class;
    $real_load_path  = realpath($load_path);

    // validate the path
    if (!file_exists($real_load_path)) {
        throw new \Exception("Cannot autoload path '$load_path'");
    }
    return $load_path;
}