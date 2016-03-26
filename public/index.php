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
render($responseToRender, $config);
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
    $app->api->request = $app->service->get(Request::class);

    // find the correct controller to use; this is determined from the request
    $controllerRef = $app->api->findController($app->api->request);

    // instantiate the controller
    $app->api->controller = $app->service->get($controllerRef);

    // trigger the controller to build a response
    $responseToRender = $app->api->controller->trigger($app->service);

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
 * @param        $responseToRender
 * @param Config $config
 */
function render($responseToRender, Config $config)
{
    // determine response code
    $responseCode = $responseToRender[$config->responseLeaderKey]->code;

    // encode the response to JSON
    $json = json_encode((object)$responseToRender,JSON_PRETTY_PRINT);

    // remove null bytes, which can be a gotcha upon decoding
    $json = str_replace('\\u0000', '', $json);

    // if the JSON is invalid, dump the raw response
    if (!isJson($json)) {
        Debug::dump($responseToRender);
        die();
    }

    // render as JSON
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
