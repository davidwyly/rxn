<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;

final class JoinTest extends TestCase
{
    public function testJoin()
    {
        $query = new Query();
        $query->select(['users.id' => 'user_id'])
              ->from('users', 'u')
              ->join('orders', 'orders.user_id', '=', 'users.id', 'o')
              ->where('users.id','=',12345)
              ->parseCommandAliases();
        $this->assertEquals('`u`.`id` AS `user_id`', $query->commands['SELECT'][0]);
        $this->assertEquals('`users` AS `u`', $query->commands['FROM'][0]);
        $expected = [
            'orders' => [
                'AS' => ['`o`'],
                'ON' => ['`o`.`user_id` = `u`.`id`'],
            ],
        ];
        $this->assertEquals($expected, $query->commands['INNER JOIN']);
    }
}
