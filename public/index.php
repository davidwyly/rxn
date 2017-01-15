<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

require_once('../bootstrap.php');

try {
    $config      = new \Rxn\Config();
    $datasources = new \Rxn\Datasources();
    $service     = new \Rxn\Service();
    $app         = new \Rxn\Application($config, $datasources, $service);
} catch (\Exception $e) {
    \Rxn\Application::appendEnvironmentError($e);
    \Rxn\Application::renderEnvironmentErrors();
}

$app->run();