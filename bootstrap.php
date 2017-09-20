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

/**
 * Instantiate autoloader and validate
 */
$autoload = Autoload($config);
$autoload->validateEnvironment();