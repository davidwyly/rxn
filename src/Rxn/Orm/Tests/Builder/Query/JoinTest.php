<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Query\Join;
use Rxn\Orm\Builder\Query\Where;

final class JoinTest extends TestCase
{
    public function testJoin()
    {
        $query = new Query();
        $query->select(['users.id' => 'user_id'])
              ->from('users', 'o')
              ->leftJoin('orders', 'orders.user_id', '=', 'users.id', 'o', function (Join $join) {
                  $join->whereIsNull('users.test2','and',true, function (Where $where) {
                      $where->orIsNotNull('users.test3');
                  });
              })
              ->join('invoices', 'invoices.id', '=', 'orders.invoice_id', 'i')
              ->where('users.first_name', '=', 'David', function (Where $where) {
                  $where->and('users.last_name', '=', 'Wyly');
                  $where->andIn('users.type', [1, 2, 3]);
              })
              ->and('users.first_name', '=', 'Lance', function (Where $where) {
                  $where->and('users.last_name', '=', 'Badger');
              })
              ->or('users.first_name2', '=', 'Joseph', function (Where $where) {
                  $where->and('users.last_name2', '=', 'Andrews', function (Where $where) {
                      $where->or('users.last_name2', '=', 'Andrews, III');
                  });
              });

        $this->assertEquals('`users`.`id` AS `user_id`', $query->commands['SELECT'][0]);

        $this->assertEquals('`users`', $query->commands['FROM'][0]);
        $expected_join = [
            'orders' => [
                'ON' => ['`orders`.`user_id` = `users`.`id`'],
            ],
        ];

        $this->assertEquals($expected_join, $query->commands['INNER JOIN']);
    }

    public function testJoinParsed()
    {
        $query = new Query();
        $query->select(['users.id' => 'user_id'])
              ->from('users', 'u')
              ->join('orders', 'orders.user_id', '=', 'users.id', 'o')
              ->parseCommandAliases();

        $expected_table_aliases = [
            'users'  => 'u',
            'orders' => 'o',
        ];
        $this->assertEquals($expected_table_aliases, $query->table_aliases);

        $this->assertEquals('`u`.`id` AS `user_id`', $query->commands['SELECT'][0]);

        $this->assertEquals('`users` AS `u`', $query->commands['FROM'][0]);
        $expected_join = [
            'orders' => [
                'AS' => ['`o`'],
                'ON' => ['`o`.`user_id` = `u`.`id`'],
            ],
        ];

        $this->assertEquals($expected_join, $query->commands['INNER JOIN']);
    }
}
