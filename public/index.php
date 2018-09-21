<?php

require_once(__DIR__ . '/../vendor/autoload.php');

define(__NAMESPACE__ . '\START', microtime(true));
define(__NAMESPACE__ . '\ROOT', __DIR__ . '/../');

try {
    $config = new Rxn\Framework\BaseConfig();
    $app = new App($config, new Datasource(), new Container());
} catch (AppException $exception) {
    /** @noinspection PhpUnhandledExceptionInspection */
    App::renderEnvironmentErrors($exception);
    die();
}

$app->run();
