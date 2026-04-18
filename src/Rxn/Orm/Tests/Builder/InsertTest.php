<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Raw;

final class InsertTest extends TestCase
{
    public function testSingleRow(): void
    {
        [$sql, $bindings] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com', 'role' => 'admin'])
            ->toSql();

        $this->assertSame('INSERT INTO `users` (`email`, `role`) VALUES (?, ?)', $sql);
        $this->assertSame(['a@example.com', 'admin'], $bindings);
    }

    public function testMultiRow(): void
    {
        [$sql, $bindings] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com', 'role' => 'admin'])
            ->row(['email' => 'b@example.com', 'role' => 'member'])
            ->toSql();

        $this->assertSame(
            'INSERT INTO `users` (`email`, `role`) VALUES (?, ?), (?, ?)',
            $sql
        );
        $this->assertSame(
            ['a@example.com', 'admin', 'b@example.com', 'member'],
            $bindings
        );
    }

    public function testRowsHelperIsEquivalentToRepeatedRow(): void
    {
        [$sqlA, $bindingsA] = (new Insert())
            ->into('users')
            ->rows([
                ['email' => 'a@example.com', 'role' => 'admin'],
                ['email' => 'b@example.com', 'role' => 'member'],
            ])
            ->toSql();

        [$sqlB, $bindingsB] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com', 'role' => 'admin'])
            ->row(['email' => 'b@example.com', 'role' => 'member'])
            ->toSql();

        $this->assertSame($sqlA, $sqlB);
        $this->assertSame($bindingsA, $bindingsB);
    }

    public function testMissingColumnsBindAsNull(): void
    {
        [$sql, $bindings] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com', 'role' => 'admin'])
            ->row(['email' => 'b@example.com']) // no role
            ->toSql();

        $this->assertSame('INSERT INTO `users` (`email`, `role`) VALUES (?, ?), (?, ?)', $sql);
        $this->assertSame(['a@example.com', 'admin', 'b@example.com', null], $bindings);
    }

    public function testRawValueEmitsVerbatim(): void
    {
        [$sql, $bindings] = (new Insert())
            ->into('users')
            ->row(['email' => 'a@example.com', 'created_at' => Raw::of('NOW()')])
            ->toSql();

        $this->assertSame('INSERT INTO `users` (`email`, `created_at`) VALUES (?, NOW())', $sql);
        $this->assertSame(['a@example.com'], $bindings);
    }

    public function testRowRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Insert())->into('users')->row([]);
    }

    public function testToSqlRequiresInto(): void
    {
        $this->expectException(\LogicException::class);
        (new Insert())->row(['email' => 'x'])->toSql();
    }

    public function testToSqlRequiresAtLeastOneRow(): void
    {
        $this->expectException(\LogicException::class);
        (new Insert())->into('users')->toSql();
    }
}
