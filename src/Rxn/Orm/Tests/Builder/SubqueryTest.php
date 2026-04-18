<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Raw;

final class SubqueryTest extends TestCase
{
    public function testWhereInSubquery(): void
    {
        $admins = (new Query())
            ->select(['id'])
            ->from('users')
            ->where('role', '=', 'admin');

        [$sql, $bindings] = (new Query())
            ->select()
            ->from('posts')
            ->where('author_id', 'IN', $admins)
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `posts` WHERE `author_id` IN (SELECT `id` FROM `users` WHERE `role` = ?)',
            $sql
        );
        $this->assertSame(['admin'], $bindings);
    }

    public function testWhereNotInSubqueryWithOuterConditions(): void
    {
        $banned = (new Query())
            ->select(['user_id'])
            ->from('bans')
            ->where('active', '=', 1);

        [$sql, $bindings] = (new Query())
            ->select(['id', 'email'])
            ->from('users')
            ->where('active', '=', 1)
            ->andWhere('id', 'NOT IN', $banned)
            ->toSql();

        $this->assertSame(
            'SELECT `id`, `email` FROM `users` '
            . 'WHERE `active` = ? AND `id` NOT IN (SELECT `user_id` FROM `bans` WHERE `active` = ?)',
            $sql
        );
        $this->assertSame([1, 1], $bindings);
    }

    public function testFromSubquery(): void
    {
        $frequent = (new Query())
            ->select(['user_id', Raw::of('COUNT(*) AS order_count')])
            ->from('orders')
            ->groupBy('user_id')
            ->having('COUNT(*) > 5');

        [$sql, $bindings] = (new Query())
            ->select(['*'])
            ->from($frequent, 'frequent')
            ->where('order_count', '>', 10)
            ->toSql();

        $this->assertSame(
            'SELECT * FROM (SELECT `user_id`, COUNT(*) AS order_count '
            . 'FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > 5) AS `frequent` '
            . 'WHERE `order_count` > ?',
            $sql
        );
        $this->assertSame([10], $bindings);
    }

    public function testFromSubqueryRequiresAlias(): void
    {
        $sub = (new Query())->select()->from('users');
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->from($sub);
    }

    public function testSelectSubqueryAsColumn(): void
    {
        $orderCount = (new Query())
            ->select([Raw::of('COUNT(*)')])
            ->from('orders')
            ->where('user_id', '=', Raw::of('u.id'));

        [$sql, $bindings] = (new Query())
            ->select(['u.id', 'u.email'])
            ->selectSubquery($orderCount, 'order_count')
            ->from('users', 'u')
            ->where('u.active', '=', 1)
            ->toSql();

        $this->assertSame(
            'SELECT `u`.`id`, `u`.`email`, '
            . '(SELECT COUNT(*) FROM `orders` WHERE `user_id` = u.id) AS `order_count` '
            . 'FROM `users` AS `u` WHERE `u`.`active` = ?',
            $sql
        );
        $this->assertSame([1], $bindings);
    }

    public function testSelectSubqueryMergesBindingsBeforeOuterWhere(): void
    {
        $tenantScoped = (new Query())
            ->select([Raw::of('COUNT(*)')])
            ->from('orders')
            ->where('tenant_id', '=', 7);

        [$sql, $bindings] = (new Query())
            ->select(['u.id'])
            ->selectSubquery($tenantScoped, 'cnt')
            ->from('users', 'u')
            ->where('u.active', '=', 1)
            ->toSql();

        // Sub-query placeholder appears before the WHERE placeholder,
        // so its binding (7) must come first in the positional list.
        $this->assertStringContainsString('(SELECT COUNT(*) FROM `orders` WHERE `tenant_id` = ?) AS `cnt`', $sql);
        $this->assertSame([7, 1], $bindings);
    }

    public function testSelectSubqueryRequiresAlias(): void
    {
        $sub = (new Query())->select()->from('users');
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->selectSubquery($sub, '');
    }
}
