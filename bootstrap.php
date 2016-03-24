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

validateEnvironment($root);

require_once("$root/rxn/Service.class.php");
require_once("$root/rxn/Config.class.php");
require_once("$root/rxn/service/Registry.class.php");
require_once("$root/rxn/utility/Debug.class.php");
require_once("$root/rxn/data/Database.class.php");
require_once("$root/rxn/Application.class.php");

function validateEnvironment($root) {
    if (empty(ini_get('display_errors'))) {
        exit(json_encode("RXN requires PHP ini setting 'display_errors = On'"));
    }

    if (!in_array('mod_rewrite',apache_get_modules())) {
        exit(json_encode("RXN requires Apache module 'mod_rewrite' to be enabled"));
    }

    if (!file_exists("$root/rxn/Config.class.php")) {
        exit(json_encode("RXN config file is missing; ensure that one was created from 'rxn/Config.class.php.sample'"));
    }
}