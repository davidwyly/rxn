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
use \Rxn\Utility\Debug;
use \Rxn\Api\Request;
use \Rxn\Api\Controller;
use \Rxn\Api\Controller\Response;

require_once('../bootstrap.php');

// instantiate
$config = new Config();
$database = new Database($config);
$app = new Application($config, $database);

try {
    $responseToRender = getSuccessResponse($app);
} catch (\Exception $e) {
    $responseToRender = getFailureResponse($app, $e);
}
render($responseToRender);
die();

/**
 * @param Application $app
 *
 * @return array
 * @throws Exception
 */
function getSuccessResponse(Application $app)
{
    // instantiate request model
    $request = $app->service->get(Request::class); /* @var $request Request */

    // find controller class reference
    $controllerRef = $app->api->findController($request);

    // instantiate the controller
    $app->api->controller = $app->service->get($controllerRef); /* @var $controller Controller */

    // trigger the controller to build a response
    $responseToRender = $app->api->controller->trigger();

    // return response
    return $responseToRender;
}

/**
 * @param Application $app
 * @param \Exception   $e
 *
 * @throws \Exception
 */
function getFailureResponse(Application $app, \Exception $e)
{
    // instantiate request model using the DI service container
    $response = $app->service->get(Response::class);
    
    // build a response
    $responseToRender = $response->getFailure($e);

    // return response
    return $responseToRender;
}

/**
 * @param $responseToRender
 */
function render($responseToRender)
{
    $responseCode = $responseToRender['_rxn']->code;
    $json = json_encode((object)$responseToRender,JSON_PRETTY_PRINT);
    if (!isJson($json)) {
        Debug::dump($responseToRender);
        die();
    }
    header('content-type: application/json');
    http_response_code($responseCode);
    echo($json);
}

/**
 * @param $json
 *
 * @return bool
 */
function isJson($json) {
    json_decode($json);
    return (json_last_error()===JSON_ERROR_NONE);
}
