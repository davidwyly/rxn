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
    $responseToRender = runApplication($app);
} catch (\Exception $e) {
    renderFailure($app, $e);
    exit();
}
renderAndDie($responseToRender);

/**
 * @param Application $app
 *
 * @return array
 * @throws Exception
 */
function runApplication(Application $app)
{
    // instantiate request model
    $request = $app->service->get(Request::class); /* @var $request Request */

    // find controller class reference
    $controllerRef = $app->api->findController($request);

    // instantiate the controller
    $app->api->controller = $app->service->get($controllerRef); /* @var $controller Controller */

    // trigger the controller to build a response
    $responseToRender = $app->api->controller->trigger();

    // render
    return $responseToRender;
}

/**
 * @param Application $app
 * @param Exception   $e
 *
 * @throws Exception
 */
function renderFailure(Application $app, Exception $e)
{
    // instantiate request model using the DI service container
    $response = $app->service->get(Response::class); /* @var $request Request */

    // build a response
    $responseToRender = $response->getFailure($e);

    // render
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
