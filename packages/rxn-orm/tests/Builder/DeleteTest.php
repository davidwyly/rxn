<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Delete;
use Rxn\Orm\Builder\Query;

final class DeleteTest extends TestCase
{
    public function testSimpleDelete(): void
    {
        [$sql, $bindings] = (new Delete())
            ->from('users')
            ->where('id', '=', 42)
            ->toSql();

        $this->assertSame('DELETE FROM `users` WHERE `id` = ?', $sql);
        $this->assertSame([42], $bindings);
    }

    public function testMultipleConditions(): void
    {
        [$sql, $bindings] = (new Delete())
            ->from('sessions')
            ->where('expires_at', '<', '2025-01-01')
            ->orWhereIsNull('user_id')
            ->toSql();

        $this->assertSame(
            'DELETE FROM `sessions` WHERE `expires_at` < ? OR `user_id` IS NULL',
            $sql
        );
        $this->assertSame(['2025-01-01'], $bindings);
    }

    public function testGroupedWhereCallback(): void
    {
        [$sql, $bindings] = (new Delete())
            ->from('users')
            ->where('active', '=', 0)
            ->andWhere('role', '=', 'guest', function (Query $w) {
                $w->orWhereIn('role', ['banned', 'deleted']);
            })
            ->toSql();

        $this->assertSame(
            'DELETE FROM `users` WHERE `active` = ? AND (`role` = ? OR `role` IN (?, ?))',
            $sql
        );
        $this->assertSame([0, 'guest', 'banned', 'deleted'], $bindings);
    }

    public function testEmptyWhereIsBlockedByDefault(): void
    {
        $this->expectException(\LogicException::class);
        (new Delete())->from('users')->toSql();
    }

    public function testAllowEmptyWhereOptsOut(): void
    {
        [$sql, $bindings] = (new Delete())
            ->from('users')
            ->allowEmptyWhere()
            ->toSql();

        $this->assertSame('DELETE FROM `users`', $sql);
        $this->assertSame([], $bindings);
    }

    public function testToSqlRequiresFrom(): void
    {
        $this->expectException(\LogicException::class);
        (new Delete())->where('id', '=', 1)->toSql();
    }
}
