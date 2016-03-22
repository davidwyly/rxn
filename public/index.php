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

use \Rxn\Config;
use \Rxn\Application;
use \Rxn\Utility\Debug;
use \Rxn\Router\Collector;
use \Rxn\Api\Controller;
use \Rxn\Api\Controller\Response;

if (empty(ini_get('display_errors'))) {
    exit("Note: PHP ini setting 'display_errors = On' must be set for RXN to work properly");
}

require_once('../bootstrap.php');

try {
    $responseToRender = runApplication();
} catch (\Exception $e) {
    renderFailure($e);
    exit();
}
renderAndDie($responseToRender);

function runApplication()
{
    // load the application
    $config = new Config();
    $app = new Application($config);
    $collector = $app->router->collector;
    $controller = $app->api->invokeController($collector);
    $response = new Response($collector);
    /** @var $controller Controller $response */
    $responseToRender = $controller->trigger($response);
    return $responseToRender;
}

function renderFailure(Exception $e)
{
    $collector = new Collector();
    $response = new Response($collector);
    $responseToRender = $response->getFailure($e);
    renderAndDie($responseToRender);
}

function renderAndDie($responseToRender)
{
    //ob_start('ob_gzhandler');
    $json = json_encode((object)$responseToRender,JSON_PRETTY_PRINT);
    if (!isJson($json)) {
        Debug::dump($responseToRender);
        die();
    }
    header('content-type: application/json');
    echo($json);
}

function isJson($json) {
    json_decode($json);
    return (json_last_error()===JSON_ERROR_NONE);
}
