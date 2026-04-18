<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Raw;

final class RawTest extends TestCase
{
    public function testRawAsNumericalSelectColumn(): void
    {
        [$sql] = (new Query())
            ->select([Raw::of('COUNT(*) AS total'), 'id'])
            ->from('users')
            ->toSql();

        $this->assertSame('SELECT COUNT(*) AS total, `id` FROM `users`', $sql);
    }

    public function testRawAsAlias(): void
    {
        [$sql] = (new Query())
            ->select(['user.id' => Raw::of('user_id')])
            ->from('users')
            ->toSql();

        $this->assertSame('SELECT `user`.`id` AS user_id FROM `users`', $sql);
    }

    public function testRawInGroupBy(): void
    {
        [$sql] = (new Query())
            ->select()
            ->from('orders')
            ->groupBy(Raw::of('DATE(created_at)'))
            ->toSql();

        $this->assertSame('SELECT * FROM `orders` GROUP BY DATE(created_at)', $sql);
    }

    public function testRawInOrderBy(): void
    {
        [$sql] = (new Query())
            ->select()
            ->from('users')
            ->orderBy(Raw::of('RAND()'))
            ->toSql();

        $this->assertSame('SELECT * FROM `users` ORDER BY RAND() ASC', $sql);
    }

    public function testRawStringifies(): void
    {
        $raw = Raw::of('NOW()');
        $this->assertSame('NOW()', (string)$raw);
        $this->assertSame('NOW()', $raw->sql);
    }
}
