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

validateServerSettings();

$root = __DIR__;

if (!file_exists("$root/rxn/Config.class.php")) {
    throw new \Exception("Config file is missing; ensure that one was created from 'rxn/Config.class.php.sample'");
}
require_once("$root/rxn/Service.class.php");
require_once("$root/rxn/Config.class.php");
require_once("$root/rxn/service/Registry.class.php");
require_once("$root/rxn/utility/Debug.class.php");
require_once("$root/rxn/data/Database.class.php");
require_once("$root/rxn/Application.class.php");

function validateServerSettings() {
    if (empty(ini_get('display_errors'))) {
        exit("PHP ini setting 'display_errors = On' must be set for RXN to work properly");
    }

    if (!in_array('mod_rewrite',apache_get_modules())) {
        throw new \Exception("Apache module 'mod_rewrite' must be enabled for RXN to work properly");
    }
}