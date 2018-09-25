<?php declare(strict_types=1);

namespace Rxn\Framework;

use Rxn\Orm\Builder\Join;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Builder\QueryTest;

require_once(__DIR__ . '/../app/Config/bootstrap.php');

try {
    $test = new Query();
    $test2 = new QueryTest;
    $test2->testJoin();
    $test->select([
            'u.id AS user_id',
            'o.id AS order_id',
        ])
        ->from('orders AS o')
        ->joinCustom('users AS u', function (Join $join) {
            $join->on('u.id', 'IN', [11, 22, 33]);
        })
        ->where('id', 'IN', [1, 2, 3])
        ->andWhere('u.id', '=', 255)
        ->orWhere('u.id', 'NOT IN', [55, 65]);
    $test->select([
        'u.id'  => 'user_id',
        'o.id' => 'order_id',
         ])
         ->from('orders', 'o')
         ->joinCustom('users', function (Join $join) {
             $join->on('users.id', 'IN', [11, 22, 33]);
         }, 'u')
         ->where('id', 'IN', [1, 2, 3])
         ->andWhere('user.id', '=', 255)
         ->orWhere('user.id', 'NOT IN', [55, 65]);
    $app = new App();
} catch (Error\AppException $exception) {
    /** @noinspection PhpUnhandledExceptionInspection */
    App::renderEnvironmentErrors($exception);
    die();
}

$app->run();
