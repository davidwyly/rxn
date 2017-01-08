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

$root = __DIR__;
$appRoot = 'rxn';

require_once("$root/$appRoot/Application.class.php");
require_once("$root/$appRoot/BaseConfig.class.php");
require_once("$root/$appRoot/Config.class.php");
require_once("$root/$appRoot/Service.class.php");
require_once("$root/$appRoot/service/Registry.class.php");
require_once("$root/$appRoot/utility/Debug.class.php");
require_once("$root/$appRoot/data/Database.class.php");
validateEnvironment($root,$appRoot);

function validateEnvironment($root,$appRoot) {

    if (!file_exists("$root/$appRoot/Config.class.php")) {
        try {
            throw new \Exception("RXN config file is missing; ensure that one was created from the sample file");
        } catch (\Exception $e) {
            \Rxn\Application::appendEnvironmentError($e);
        }
    }

    if (empty(ini_get('display_errors'))) {
        try {
            throw new \Exception("RXN requires PHP ini setting 'display_errors = On'");
        } catch (\Exception $e) {
            \Rxn\Application::appendEnvironmentError($e);
        }
    }

    if (empty(ini_get('zend.multibyte'))) {
        try {
            throw new \Exception("RXN requires PHP ini setting 'zend.multibyte = On'");
        } catch (\Exception $e) {
            \Rxn\Application::appendEnvironmentError($e);
        }
    }

    if (!function_exists('mb_strtolower')) {
        try {
            throw new \Exception("RXN requires the PHP mbstring extension to be installed/enabled");
        } catch (\Exception $e) {
            \Rxn\Application::appendEnvironmentError($e);
        }
    }

    if (!file_exists("$root/$appRoot/data/filecache")) {
        try {
            throw new \Exception("RXN requires for folder '$root/$appRoot/data/filecache' to exist");
        } catch (\Exception $e) {
            \Rxn\Application::appendEnvironmentError($e);
        }
    }

    if (!is_writable("$root/$appRoot/data/filecache")) {
        try {
            throw new \Exception("RXN requires for folder '$root/$appRoot/data/filecache' to be writable");
        } catch (\Exception $e) {
            \Rxn\Application::appendEnvironmentError($e);
        }
    }

    if (function_exists('apache_get_modules')) {
        if (!in_array('mod_rewrite',apache_get_modules())) {
            try {
                throw new \Exception("RXN requires Apache module 'mod_rewrite' to be enabled");
            } catch (\Exception $e) {
                \Rxn\Application::appendEnvironmentError($e);
            }
        }
    }
}