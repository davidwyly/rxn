<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Data;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Chain;
use Rxn\Framework\Data\Map;
use Rxn\Framework\Data\Map\Chain\Link;
use Rxn\Framework\Data\Map\Table;

final class ChainTest extends TestCase
{
    /**
     * Build a Table without touching the database by bypassing the
     * constructor and writing the properties directly via
     * reflection.
     */
    private function buildTable(string $name, array $fieldReferences, array $primaryKeys = ['id']): Table
    {
        $table      = (new \ReflectionClass(Table::class))->newInstanceWithoutConstructor();
        $reflection = new \ReflectionObject($table);

        $reflection->getProperty('name')->setValue($table, $name);

        $fieldRef = $reflection->getProperty('field_references');
        $fieldRef->setAccessible(true);
        $fieldRef->setValue($table, $fieldReferences);

        $pks = $reflection->getProperty('primary_keys');
        $pks->setAccessible(true);
        $pks->setValue($table, $primaryKeys);

        return $table;
    }

    private function buildMap(array $tables): Map
    {
        $map        = (new \ReflectionClass(Map::class))->newInstanceWithoutConstructor();
        $reflection = new \ReflectionObject($map);
        $prop       = $reflection->getProperty('tables');
        $prop->setAccessible(true);
        $prop->setValue($map, $tables);
        return $map;
    }

    public function testBuildsLinkForEachForeignKey(): void
    {
        $users  = $this->buildTable('users', []);
        $orders = $this->buildTable('orders', [
            'user_id' => ['schema' => 'app', 'table' => 'users', 'column' => 'id'],
        ]);

        $chain = new Chain($this->buildMap(['users' => $users, 'orders' => $orders]));

        $this->assertCount(1, $chain->all());
        $link = $chain->all()[0];
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('orders.user_id->users.id', $link->signature());
    }

    public function testBelongsToAndHasManyIndexes(): void
    {
        $users    = $this->buildTable('users', []);
        $orders   = $this->buildTable('orders', [
            'user_id' => ['schema' => 'app', 'table' => 'users', 'column' => 'id'],
        ]);
        $invoices = $this->buildTable('invoices', [
            'order_id' => ['schema' => 'app', 'table' => 'orders', 'column' => 'id'],
        ]);

        $chain = new Chain($this->buildMap([
            'users'    => $users,
            'orders'   => $orders,
            'invoices' => $invoices,
        ]));

        $belongsToUser = $chain->belongsTo('orders');
        $this->assertCount(1, $belongsToUser);
        $this->assertSame('users', $belongsToUser[0]->toTable);

        $userHasMany = $chain->hasMany('users');
        $this->assertCount(1, $userHasMany);
        $this->assertSame('orders', $userHasMany[0]->fromTable);

        $this->assertSame([], $chain->belongsTo('users'));
    }

    public function testFallsBackToPrimaryKeyWhenReferencedColumnIsEmpty(): void
    {
        $users  = $this->buildTable('users', [], ['id']);
        $orders = $this->buildTable('orders', [
            'user_id' => ['schema' => 'app', 'table' => 'users', 'column' => ''],
        ]);

        $chain = new Chain($this->buildMap(['users' => $users, 'orders' => $orders]));
        $this->assertSame('orders.user_id->users.id', $chain->all()[0]->signature());
    }

    public function testSkipsReferencesToUnknownTables(): void
    {
        $orders = $this->buildTable('orders', [
            'user_id' => ['schema' => 'app', 'table' => 'users', 'column' => 'id'],
        ]);

        $chain = new Chain($this->buildMap(['orders' => $orders]));
        $this->assertSame([], $chain->all(), 'unknown target tables should not produce a Link');
    }
}
