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

use \Rxn\Service;
use \Rxn\Config;
use \Rxn\Application;
use \Rxn\Utility\Debug;
use \Rxn\Api\Request;
use \Rxn\Api\Controller;
use \Rxn\Api\Controller\Response;

require_once('../bootstrap.php');

// instantiate DI service container
$service = new Service();

try {
    $responseToRender = runApplication($service);
} catch (\Exception $e) {
    renderFailure($service, $e);
    exit();
}
renderAndDie($responseToRender);

function runApplication(Service $service)
{
    // instantiate application
    $app = $service->get(Application::class); /* @var $app Application */
    $request = $service->get(Request::class); /* @var $request Request */
    $response = $service->get(Response::class); /* @var $response Response */
    $controller = $app->api->loadController($request,$response); /* @var $controller Controller */
    $responseToRender = $controller->trigger($response);
    return $responseToRender;
}

function renderFailure(Service $service, Exception $e)
{
    $request = $service->get(Request::class); /* @var $request Request */
    $response = new Response($request);
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
