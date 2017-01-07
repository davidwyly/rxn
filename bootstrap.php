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

validateEnvironment($root,$appRoot);
require_once("$root/$appRoot/BaseConfig.class.php");
require_once("$root/$appRoot/Config.class.php");
require_once("$root/$appRoot/Service.class.php");
require_once("$root/$appRoot/service/Registry.class.php");
require_once("$root/$appRoot/utility/Debug.class.php");
require_once("$root/$appRoot/data/Database.class.php");
require_once("$root/$appRoot/Application.class.php");

function validateEnvironment($root,$appRoot) {

    $environmentErrors = [];
    if (empty(ini_get('display_errors'))) {
        array_push($environmentErrors,"RXN requires PHP ini setting 'display_errors = On'");
    }

    if (empty(ini_get('zend.multibyte'))) {
        array_push($environmentErrors,"RXN requires PHP ini setting 'zend.multibyte = On'");
    }

    if (!function_exists('mb_strtolower')) {
        array_push($environmentErrors,"RXN requires the PHP mbstring extension to be installed/enabled");
    }

    if (function_exists('apache_get_modules')) {
        if (!in_array('mod_rewrite',apache_get_modules())) {
            array_push($environmentErrors,"RXN requires Apache module 'mod_rewrite' to be enabled");
        }
    }

    if (!file_exists("$root/$appRoot/Config.class.php")) {
        array_push($environmentErrors,"RXN config file is missing; ensure that one was created from the sample file");
    }

    if (!file_exists("$root/$appRoot/data/filecache")) {
        array_push($environmentErrors,"RXN requires for folder '$root/$appRoot/data/filecache' to exist");
    }

    if (!is_writable("$root/$appRoot/data/filecache")) {
        array_push($environmentErrors,"RXN requires for folder '$root/$appRoot/data/filecache' to be writable");
    }

    if (!empty($environmentErrors)) {
        renderEnvironmentError($environmentErrors);
    }
}

function renderEnvironmentError($environmentErrors) {
    $response = [
        '_rxn' => [
            'success' => false,
            'code' => 500,
            'result' => 'Internal Server Error',
            'message' => $environmentErrors,
        ],
    ];
    http_response_code(500);
    header('content-type: application/json');
    echo json_encode($response,JSON_PRETTY_PRINT);
    die();
}