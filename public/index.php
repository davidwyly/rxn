<?php declare(strict_types=1);

namespace Rxn\Framework;

use Rxn\Orm\Builder\Join;
use Rxn\Orm\Builder\Query;

require_once(__DIR__ . '/../app/Config/bootstrap.php');

try {
    $test = new Query();
    $test->select()
         ->from('orders')
         ->joinCustom('users',function (Join $join) {
             $join->on('users.id','IN',[11,22,33]);
         })
         ->where('id','IN', [1,2,3])
         ->andWhere('user.id', '=', 255)
         ->orWhere('user.id', 'NOT IN', [55,65]);
    $app = new App();
} catch (Error\AppException $exception) {
    /** @noinspection PhpUnhandledExceptionInspection */
    App::renderEnvironmentErrors($exception);
    die();
}

$app->run();
