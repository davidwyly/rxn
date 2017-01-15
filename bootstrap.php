<?php
/**
 *
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 *
 */

/**
 * Definitions
 */
define('RXN_START',microtime(true));
define('RXN_BASE_ROOT' , __DIR__);
define('RXN_APP_ROOT'  , 'rxn');
define('RXN_ROOT' , RXN_BASE_ROOT . "/" . RXN_APP_ROOT . "/");

/**
 * Begin output buffering
 */
ob_start();

/**
 * Require prerequisites
 */
require_once(RXN_ROOT . "Application.class.php");
require_once(RXN_ROOT . "ApplicationConfig.class.php");
require_once(RXN_ROOT . "ApplicationDatasources.class.php");

/**
 * Require core components and validate
 */
\Rxn\Application::includeCoreComponents(RXN_BASE_ROOT,RXN_APP_ROOT);
\Rxn\Application::validateEnvironment(RXN_BASE_ROOT,RXN_APP_ROOT);