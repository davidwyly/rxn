<?php declare(strict_types=1);

namespace Rxn\Framework;

require_once(__DIR__ . '/../app/Config/bootstrap.php');

try {
    $app = new App();
} catch (Error\AppException $exception) {
    /** @noinspection PhpUnhandledExceptionInspection */
    App::renderEnvironmentErrors($exception);
    die();
}

$app->run();
