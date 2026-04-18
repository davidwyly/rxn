<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Delete;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Raw;
use Rxn\Orm\Builder\Update;

final class UpsertReturningTest extends TestCase
{
    public function testOnDuplicateKeyUpdateAppendsClauseAndBindings(): void
    {
        [$sql, $bindings] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com', 'visits' => 1])
            ->onDuplicateKeyUpdate(['visits' => Raw::of('visits + 1')])
            ->toSql();

        $this->assertSame(
            'INSERT INTO `users` (`email`, `visits`) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE `visits` = visits + 1',
            $sql
        );
        $this->assertSame(['a@example.com', 1], $bindings);
    }

    public function testOnDuplicateKeyUpdateWithBoundValues(): void
    {
        [$sql, $bindings] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com', 'role' => 'member'])
            ->onDuplicateKeyUpdate(['role' => 'admin', 'updated_at' => Raw::of('NOW()')])
            ->toSql();

        $this->assertSame(
            'INSERT INTO `users` (`email`, `role`) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE `role` = ?, `updated_at` = NOW()',
            $sql
        );
        $this->assertSame(['a@example.com', 'member', 'admin'], $bindings);
    }

    public function testOnDuplicateKeyUpdateEmptyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Insert())
            ->into('users')
            ->row(['email' => 'x'])
            ->onDuplicateKeyUpdate([]);
    }

    public function testInsertReturningAppendsColumns(): void
    {
        [$sql] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com'])
            ->returning('id', 'email')
            ->toSql();

        $this->assertSame(
            'INSERT INTO `users` (`email`) VALUES (?) RETURNING `id`, `email`',
            $sql
        );
    }

    public function testInsertReturningAcceptsRaw(): void
    {
        [$sql] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com'])
            ->returning(Raw::of('id, COALESCE(role, \'member\') AS role'))
            ->toSql();

        $this->assertSame(
            "INSERT INTO `users` (`email`) VALUES (?) RETURNING id, COALESCE(role, 'member') AS role",
            $sql
        );
    }

    public function testUpdateReturning(): void
    {
        [$sql, $bindings] = (new Update())
            ->table('users')
            ->set(['active' => 0])
            ->where('id', '=', 42)
            ->returning('id', 'active')
            ->toSql();

        $this->assertSame(
            'UPDATE `users` SET `active` = ? WHERE `id` = ? RETURNING `id`, `active`',
            $sql
        );
        $this->assertSame([0, 42], $bindings);
    }

    public function testDeleteReturning(): void
    {
        [$sql, $bindings] = (new Delete())
            ->from('users')
            ->where('id', '=', 42)
            ->returning('id', 'email')
            ->toSql();

        $this->assertSame(
            'DELETE FROM `users` WHERE `id` = ? RETURNING `id`, `email`',
            $sql
        );
        $this->assertSame([42], $bindings);
    }
}
