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
recursiveAutoload(BASE_ROOT . "/" . APP_ROOT);

//----------------------------------------------------------
//
// Function Declarations
//
//----------------------------------------------------------
/**
 * @param $directory
 */
function recursiveAutoload($directory)
{
    $contents = array_slice(scandir($directory), 2);
    foreach ($contents as $content) {
        $path = "$directory/$content";
        if (is_file($path)
            && pathinfo($path, PATHINFO_EXTENSION) == 'php'
        ) {
            spl_autoload_register(function () use ($path) {
                /** @noinspection PhpIncludeInspection */
                require_once($path);
            });
        } elseif (is_dir($path)) {
            recursiveAutoload($path);
        }
    }
}