<?php declare(strict_types=1);

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../app/Autoloader.php');

define(__NAMESPACE__ . '\START', microtime(true));
define(__NAMESPACE__ . '\ROOT', __DIR__ . '/../');

try {
    $config = new Config();
    $app = new App($config, new BaseDatasource(), new Container());
} catch (Error\AppException $exception) {
    /** @noinspection PhpUnhandledExceptionInspection */
    App::renderEnvironmentErrors($exception);
    die();
}

$app->run();
