<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;

final class WhereTest extends TestCase
{
    public function testSingleWhereEmitsPlaceholder(): void
    {
        [$sql, $bindings] = (new Query())
            ->select(['id'])
            ->from('users')
            ->where('email', '=', 'u@example.com')
            ->toSql();

        $this->assertSame('SELECT `id` FROM `users` WHERE `email` = ?', $sql);
        $this->assertSame(['u@example.com'], $bindings);
    }

    public function testAndWhereChainsWithAnd(): void
    {
        [$sql, $bindings] = (new Query())
            ->select()
            ->from('users')
            ->where('role', '=', 'admin')
            ->andWhere('active', '=', 1)
            ->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `role` = ? AND `active` = ?', $sql);
        $this->assertSame(['admin', 1], $bindings);
    }

    public function testOrWhereChainsWithOr(): void
    {
        [$sql, $bindings] = (new Query())
            ->select()
            ->from('users')
            ->where('role', '=', 'admin')
            ->orWhere('role', '=', 'owner')
            ->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `role` = ? OR `role` = ?', $sql);
        $this->assertSame(['admin', 'owner'], $bindings);
    }

    public function testGroupViaCallbackWrapsInParens(): void
    {
        [$sql, $bindings] = (new Query())
            ->select()
            ->from('users')
            ->where('active', '=', 1)
            ->andWhere('role', '=', 'admin', function (Query $w) {
                $w->orWhere('role', '=', 'owner');
            })
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `active` = ? AND (`role` = ? OR `role` = ?)',
            $sql
        );
        $this->assertSame([1, 'admin', 'owner'], $bindings);
    }

    public function testNestedGroupsPreserveStructureAndBindingOrder(): void
    {
        [$sql, $bindings] = (new Query())
            ->select()
            ->from('users')
            ->where('tenant_id', '=', 7)
            ->andWhere('status', '=', 'active', function (Query $outer) {
                $outer->orWhere('status', '=', 'trial', function (Query $inner) {
                    $inner->andWhere('trial_ends_at', '>', '2026-04-18');
                });
            })
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `tenant_id` = ? AND (`status` = ? OR (`status` = ? AND `trial_ends_at` > ?))',
            $sql
        );
        $this->assertSame([7, 'active', 'trial', '2026-04-18'], $bindings);
    }

    public function testWhereIsNull(): void
    {
        [$sql, $bindings] = (new Query())
            ->select(['id'])
            ->from('users')
            ->whereIsNull('deleted_at')
            ->andWhere('active', '=', 1)
            ->toSql();

        $this->assertSame('SELECT `id` FROM `users` WHERE `deleted_at` IS NULL AND `active` = ?', $sql);
        $this->assertSame([1], $bindings);
    }

    public function testWhereIsNotNull(): void
    {
        [$sql, $bindings] = (new Query())
            ->select()
            ->from('users')
            ->whereIsNotNull('email')
            ->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `email` IS NOT NULL', $sql);
        $this->assertSame([], $bindings);
    }

    public function testWhereIn(): void
    {
        [$sql, $bindings] = (new Query())
            ->select()
            ->from('users')
            ->whereIn('id', [1, 2, 3])
            ->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (?, ?, ?)', $sql);
        $this->assertSame([1, 2, 3], $bindings);
    }

    public function testWhereNotIn(): void
    {
        [$sql, $bindings] = (new Query())
            ->select()
            ->from('users')
            ->whereNotIn('role', ['banned', 'deleted'])
            ->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `role` NOT IN (?, ?)', $sql);
        $this->assertSame(['banned', 'deleted'], $bindings);
    }

    public function testBetween(): void
    {
        [$sql, $bindings] = (new Query())
            ->select()
            ->from('orders')
            ->where('created_at', 'BETWEEN', ['2025-01-01', '2025-12-31'])
            ->toSql();

        $this->assertSame('SELECT * FROM `orders` WHERE `created_at` BETWEEN ? AND ?', $sql);
        $this->assertSame(['2025-01-01', '2025-12-31'], $bindings);
    }

    public function testUnsupportedOperatorRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->from('users')->where('x', 'BOGUS', 1);
    }

    public function testEmptyInArrayRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->from('users')->whereIn('id', []);
    }
}
