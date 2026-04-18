<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;

/**
 * Covers the "finisher" clauses: ORDER BY, LIMIT, OFFSET, GROUP BY,
 * HAVING — plus the compound ordering between them.
 */
final class ClauseTest extends TestCase
{
    public function testOrderByAscending(): void
    {
        [$sql] = (new Query())
            ->select(['id'])
            ->from('users')
            ->orderBy('id')
            ->toSql();

        $this->assertSame('SELECT `id` FROM `users` ORDER BY `id` ASC', $sql);
    }

    public function testOrderByDescendingAndMultipleColumns(): void
    {
        [$sql] = (new Query())
            ->select()
            ->from('users')
            ->orderBy('created_at', 'DESC')
            ->orderBy('id')
            ->toSql();

        $this->assertSame('SELECT * FROM `users` ORDER BY `created_at` DESC, `id` ASC', $sql);
    }

    public function testOrderByRejectsInvalidDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->from('users')->orderBy('id', 'sideways');
    }

    public function testLimit(): void
    {
        [$sql] = (new Query())->select()->from('users')->limit(10)->toSql();
        $this->assertSame('SELECT * FROM `users` LIMIT 10', $sql);
    }

    public function testLimitAndOffset(): void
    {
        [$sql] = (new Query())->select()->from('users')->limit(25)->offset(50)->toSql();
        $this->assertSame('SELECT * FROM `users` LIMIT 25 OFFSET 50', $sql);
    }

    public function testLimitRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->from('users')->limit(-1);
    }

    public function testOffsetRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->from('users')->offset(-1);
    }

    public function testGroupBy(): void
    {
        [$sql] = (new Query())
            ->select(['user_id'])
            ->from('orders')
            ->groupBy('user_id')
            ->toSql();

        $this->assertSame('SELECT `user_id` FROM `orders` GROUP BY `user_id`', $sql);
    }

    public function testGroupByMultiple(): void
    {
        [$sql] = (new Query())
            ->select()
            ->from('orders')
            ->groupBy('user_id', 'status')
            ->toSql();

        $this->assertSame('SELECT * FROM `orders` GROUP BY `user_id`, `status`', $sql);
    }

    public function testHavingWithAggregate(): void
    {
        [$sql] = (new Query())
            ->select(['user_id'])
            ->from('orders')
            ->groupBy('user_id')
            ->having('COUNT(*) > 5')
            ->toSql();

        $this->assertSame('SELECT `user_id` FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > 5', $sql);
    }

    public function testFullCompoundQueryOrdering(): void
    {
        [$sql, $bindings] = (new Query())
            ->select(['u.id', 'u.email'])
            ->from('users', 'u')
            ->leftJoin('orders', 'o.user_id', '=', 'u.id', 'o')
            ->where('u.active', '=', 1)
            ->andWhereIsNull('u.deleted_at')
            ->groupBy('u.id')
            ->having('COUNT(o.id) > 0')
            ->orderBy('u.id', 'DESC')
            ->limit(100)
            ->offset(200)
            ->toSql();

        $this->assertSame(
            'SELECT `u`.`id`, `u`.`email` FROM `users` AS `u` '
            . 'LEFT JOIN `orders` AS `o` ON `o`.`user_id` = `u`.`id` '
            . 'WHERE `u`.`active` = ? AND `u`.`deleted_at` IS NULL '
            . 'GROUP BY `u`.`id` HAVING COUNT(o.id) > 0 '
            . 'ORDER BY `u`.`id` DESC LIMIT 100 OFFSET 200',
            $sql
        );
        $this->assertSame([1], $bindings);
    }
}
