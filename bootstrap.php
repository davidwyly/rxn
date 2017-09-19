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
 * Require core components for auto-loader
 */
require_once(ROOT . "/Service.class.php");
require_once(ROOT . "/BaseConfig.class.php");
require_once(ROOT . "/Config.class.php");
require_once(ROOT . "/Autoload.class.php");

/**
 * Validate environment
 */
$config = new Config();
validateEnvironment(ROOT, APP_ROOT, $config);

/**
 * Instantiate autoloader
 */
new Autoload($config);

function validateEnvironment($root, $app_root, Config $config)
{
    // validate PHP INI file settings
    $ini_requirements = Config::getPhpIniRequirements();
    foreach ($ini_requirements as $ini_key => $requirement) {
        if (ini_get($ini_key) != $requirement) {
            if (is_bool($requirement)) {
                $requirement = ($requirement) ? 'On' : 'Off';
            }
            throw new \Exception("Rxn requires PHP ini setting '$ini_key' = '$requirement'");
        }
    }

    // validate that file caching can work with the environment
    if ($config->use_file_caching) {
        if (!file_exists("$root/$app_root/data/filecache")) {
            throw new \Exception("Rxn requires for folder '$root/$app_root/data/filecache' to exist");
        }
        if (!is_writable("$root/$app_root/data/filecache")) {
            throw new \Exception("Rxn requires for folder '$root/$app_root/data/filecache' to be writable");
        }
    }

    // validate that multibyte extensions will work properly
    if (!function_exists('mb_strtolower')
        && (isset($ini_requirements['zend.multibyte'])
            && $ini_requirements['zend.multibyte'] !== true)
    ) {
        throw new \Exception("Rxn requires the PHP mbstring extension to be installed/enabled");
    }

    // special apache checks
    if (function_exists('apache_get_modules')
        && !in_array('mod_rewrite', apache_get_modules())
    ) {
        throw new \Exception("Rxn requires Apache module 'mod_rewrite' to be enabled");
    }
}