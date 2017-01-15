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

ob_start();

$root = __DIR__;
$appRoot = 'rxn';

require_once("$root/$appRoot/Application.class.php");
require_once("$root/$appRoot/ApplicationConfig.class.php");
require_once("$root/$appRoot/ApplicationDatasources.class.php");
\Rxn\Application::includeCoreComponents($root,$appRoot);
\Rxn\Application::validateEnvironment($root,$appRoot);

if (\Rxn\Application::hasEnvironmentErrors()) {
    \Rxn\Application::renderEnvironmentErrors();
}