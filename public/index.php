<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

use \Rxn\Service;
use \Rxn\Data\Database;
use \Rxn\Config;
use \Rxn\Application;
use \Rxn\Api\Controller;
use \Rxn\Utility\Debug;

// load the core models necessary for instantiation
require_once('../bootstrap.php');

// instantiate the application
$config = new Config();
$database = new Database($config);
$app = new Application($config, $database);

// start the application and render a response
$app->run();