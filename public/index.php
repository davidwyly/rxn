<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

require_once('../bootstrap.php');

try {
    $service = new \Rxn\Service();
    $app = new \Rxn\Application($config, new \Rxn\Datasources(), $service, \RXN_START);
} catch (\Exception $e) {
    \Rxn\Application::appendEnvironmentError($e);
    \Rxn\Application::renderEnvironmentErrors($config);
}

$app->run();