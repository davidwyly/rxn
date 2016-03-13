<?php

$root = __DIR__;

if (!file_exists("$root/rxn/Config.class.php")) {
    throw new \Exception("Config file is missing; ensure that one was created from sample");
}
require_once("$root/rxn/Config.class.php");
require_once("$root/rxn/service/Registry.class.php");
require_once("$root/rxn/utility/Debug.class.php");
require_once("$root/rxn/data/Database.class.php");
require_once("$root/rxn/Application.class.php");