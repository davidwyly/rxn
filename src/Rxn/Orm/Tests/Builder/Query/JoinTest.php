<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Command\Join;
use Rxn\Orm\Builder\Command\Where;

final class JoinTest extends TestCase
{
    public function testJoin()
    {
        $min = 5000;
        $max = 10000;

        $query = new Query();
        $query->select([
            'u.id'         => 'user_id',
            'u.first_name' => 'name_first',
            'u.last_name'  => 'name_last',
            'o.status'     => null,
            0              => 'o2.status',
        ])
        ->fromAs('users', 'u')
        ->leftJoinAs('orders', 'o', function (Join $join) {
            $join->where()->equals('o.user_id', 'u.id');
            $join->where()->notNull('o.user_id');
        })
        ->innerJoinAs('orders', 'o2', function (Join $join) {
            $join->where()->equals('o2.user_id', 'u.id');
            $join->where()->between('o2.date', new \DateTime('now'), new \DateTime('now +12 hours'));
        })
        ->where(function (Where $where) {
            $where->equals('u.status', 'active');
            $where->or(function (Where $where) {
                $where->and(function (Where $where) {
                    $where->equals('o.status', 'processing');
                    $where->equals('o.compliance_hold', 0);
                });
//                $where->and(function (Where $where) {
//                    $where->exists('o.id', (new Query())
//                                                        ->select()
//                                                        ->from('orders')
//                                                        ->where(function (Where $where) {
//                                                            $where->equals('orders.id',1);
//                                                        })
//                    );
//                    $where->equals('o.status', 'complete');
//                });
//                $where->in('o.status', ['payment_pending', 'payment_processing']);
            });
        })
        ->groupBy(['u.id'])
        ->orderBy(['u.id' => 'desc', 'o.status' => 'asc',])
        ->having(function (Where $where) use ($min, $max) {
            $where->greaterThan('SUM(o.price)', $min);
            $where->lessThan('SUM(o.price)', $max);
        })
        ->limit(1000)
        ->offset(100);
        //->union($otherQuery)
        //->fetch();


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
