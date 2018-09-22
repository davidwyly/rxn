<?php declare(strict_types=1);

namespace Rxn\Framework;

use Dotenv\Dotenv;

require_once(__DIR__ . '/../vendor/autoload.php');

/**
 * define root paths
 */
define(__NAMESPACE__ . '\\START', microtime(true));
define(__NAMESPACE__ . '\\ROOT', realpath(__DIR__ . '/..') . '/');
define(__NAMESPACE__ . '\\APP_ROOT', constant(__NAMESPACE__ . '\\ROOT') . 'app/');
define(__NAMESPACE__ . '\\CONFIG_ROOT', constant(__NAMESPACE__ . '\\ROOT') . 'app/Config/');

try {
    /**
     * process .env and bootstrap constants
     */
    $env = new Dotenv(constant(__NAMESPACE__ . '\\CONFIG_ROOT'));
    $env->load();
    unset($env);
    require_once(__DIR__ . '/../app/Config/bootstrap.php');

    /**
     * spin up the app autoloader
     */
    new Autoloader();

    $app = new App();
} catch (Error\AppException $exception) {
    /** @noinspection PhpUnhandledExceptionInspection */
    App::renderEnvironmentErrors($exception);
    die();
}

$app->run();
