<?php

namespace Rxn\Framework;

define(__NAMESPACE__ . '\START', microtime(true));
define(__NAMESPACE__ . '\ROOT', __DIR__ . '/../');
define(__NAMESPACE__ . '\RXN_ROOT', ROOT . 'vendor/davidwyly/rxn/src');
define(__NAMESPACE__ . '\APP_ROOT', ROOT . 'app/');

require_once(RXN_ROOT . '/Service.php');
require_once(RXN_ROOT . '/BaseConfig.php');
require_once(ROOT . '/config/Config.php');
require_once(RXN_ROOT . '/Autoload.php');

try {
    $config = new Config();
    new Autoload($config);
    $app = new App($config, new Datasources(), new Container());
} catch (AppException $exception) {
    /** @noinspection PhpUnhandledExceptionInspection */
    App::renderEnvironmentErrors($exception);
    die();
}

$app->run();
