<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;

final class JoinTest extends TestCase
{
    public function testJoinAccumulatesEveryEdgeIntoTheCommandTree(): void
    {
        $query = (new Query())
            ->select(['users.id' => 'user_id'])
            ->from('users')
            ->join('orders', 'orders.user_id', '=', 'users.id', 'o')
            ->join('invoices', 'invoices.id', '=', 'orders.invoice_id', 'i')
            ->where('users.first_name', '=', 'David', function (Query $where) {
                $where->and('users.last_name', '=', 'Wyly');
            })
            ->where('users.first_name', '=', 'Lance', function (Query $where) {
                $where->and('users.last_name', '=', 'Badger');
            })
            ->or('users.first_name2', '=', 'Joseph', function (Query $where) {
                $where->and('users.last_name2', '=', 'Andrews', function (Query $inner) {
                    $inner->or('users.last_name2', '=', 'Andrews, III');
                });
            });

        $this->assertSame('`users`.`id` AS `user_id`', $query->commands['SELECT'][0]);
        $this->assertSame('`users`', $query->commands['FROM'][0]);

        $this->assertSame(
            [
                'orders'   => ['AS' => ['`o`'], 'ON' => ['`orders`.`user_id` = `users`.`id`']],
                'invoices' => ['AS' => ['`i`'], 'ON' => ['`invoices`.`id` = `orders`.`invoice_id`']],
            ],
            $query->commands['INNER JOIN']
        );

        // Three top-level where entries: two grouped ANDs + one grouped OR.
        $this->assertCount(3, $query->commands['WHERE']);
        $this->assertSame('AND', $query->commands['WHERE'][0]['op']);
        $this->assertSame('AND', $query->commands['WHERE'][1]['op']);
        $this->assertSame('OR',  $query->commands['WHERE'][2]['op']);

        $this->assertSame(
            ['David', 'Wyly', 'Lance', 'Badger', 'Joseph', 'Andrews', 'Andrews, III'],
            $query->bindings
        );
    }

    public function testJoinParsed(): void
    {
        $query = (new Query())
            ->select(['users.id' => 'user_id'])
            ->from('users', 'u')
            ->join('orders', 'orders.user_id', '=', 'users.id', 'o');
        $query->parseCommandAliases();

        $this->assertSame(['users' => 'u', 'orders' => 'o'], $query->table_aliases);
        $this->assertSame('`u`.`id` AS `user_id`', $query->commands['SELECT'][0]);
        $this->assertSame('`users` AS `u`', $query->commands['FROM'][0]);
        $this->assertSame(
            ['orders' => ['AS' => ['`o`'], 'ON' => ['`o`.`user_id` = `u`.`id`']]],
            $query->commands['INNER JOIN']
        );
    }
}
