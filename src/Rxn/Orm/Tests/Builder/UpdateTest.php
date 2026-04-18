<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Raw;
use Rxn\Orm\Builder\Update;

final class UpdateTest extends TestCase
{
    public function testSimpleUpdate(): void
    {
        [$sql, $bindings] = (new Update())
            ->table('users')
            ->set(['role' => 'admin', 'active' => 1])
            ->where('id', '=', 42)
            ->toSql();

        $this->assertSame(
            'UPDATE `users` SET `role` = ?, `active` = ? WHERE `id` = ?',
            $sql
        );
        $this->assertSame(['admin', 1, 42], $bindings);
    }

    public function testMultipleWhereConditions(): void
    {
        [$sql, $bindings] = (new Update())
            ->table('users')
            ->set(['active' => 0])
            ->where('role', '=', 'member')
            ->andWhere('last_login_at', '<', '2025-01-01')
            ->toSql();

        $this->assertSame(
            'UPDATE `users` SET `active` = ? WHERE `role` = ? AND `last_login_at` < ?',
            $sql
        );
        $this->assertSame([0, 'member', '2025-01-01'], $bindings);
    }

    public function testSetMergesAcrossCalls(): void
    {
        [$sql] = (new Update())
            ->table('users')
            ->set(['role' => 'admin'])
            ->set(['active' => 1, 'role' => 'owner']) // later role wins
            ->where('id', '=', 7)
            ->toSql();

        // Column order is insertion order of the first set() call for role;
        // active was added later, so it's appended.
        $this->assertSame(
            'UPDATE `users` SET `role` = ?, `active` = ? WHERE `id` = ?',
            $sql
        );
    }

    public function testRawSetValueEmitsVerbatim(): void
    {
        [$sql, $bindings] = (new Update())
            ->table('users')
            ->set(['updated_at' => Raw::of('NOW()'), 'role' => 'admin'])
            ->where('id', '=', 42)
            ->toSql();

        $this->assertSame(
            'UPDATE `users` SET `updated_at` = NOW(), `role` = ? WHERE `id` = ?',
            $sql
        );
        $this->assertSame(['admin', 42], $bindings);
    }

    public function testGroupedWhereCallback(): void
    {
        [$sql, $bindings] = (new Update())
            ->table('users')
            ->set(['active' => 0])
            ->where('tenant_id', '=', 7)
            ->andWhere('role', '=', 'member', function (Query $w) {
                $w->orWhere('role', '=', 'guest');
            })
            ->toSql();

        $this->assertSame(
            'UPDATE `users` SET `active` = ? WHERE `tenant_id` = ? AND (`role` = ? OR `role` = ?)',
            $sql
        );
        $this->assertSame([0, 7, 'member', 'guest'], $bindings);
    }

    public function testToSqlRequiresTable(): void
    {
        $this->expectException(\LogicException::class);
        (new Update())->set(['x' => 1])->toSql();
    }

    public function testToSqlRequiresAtLeastOneAssignment(): void
    {
        $this->expectException(\LogicException::class);
        (new Update())->table('users')->toSql();
    }
}
