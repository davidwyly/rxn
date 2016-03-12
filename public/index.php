<?php

namespace Rxn;



use \Rxn\Utility\Debug;
use \Rxn\Api\Controller;

try {
    $response = runApplication();
} catch (\Exception $e) {
    renderFailure($e);
    exit();
}
renderSuccess($response);

function runApplication()
{
    // load the application
    require_once('../bootstrap.php');
    $config = new Config();
    $app = new Application($config);
    $controller = $app->api->getController($app->router->collector);
    /** @var $controller Controller $response */
    $controller->trigger();
    return $controller->response;
}

function renderFailure(\Exception $e)
{
    $response = [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'backtrace' => $e->getTrace(),
    ];
    renderAndDie($response);
}

function renderSuccess($response)
{
    renderAndDie($response);
}

function renderAndDie($response)
{
    //ob_start('ob_gzhandler');
    $json = json_encode((object)$response,JSON_PRETTY_PRINT);
    if (!isJson($json)) {
        Debug::dump($response);
        die();
    }
    header('content-type: application/json');
    die($json);
}

function isJson($json) {
    json_decode($json);
    return (json_last_error()===JSON_ERROR_NONE);
}