<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Model;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Database;
use Rxn\Framework\Model\ActiveRecord;

/**
 * Exercises the ActiveRecord layer end-to-end against an in-memory
 * sqlite PDO, covering find, hydrate, __get / __isset, hasMany /
 * hasOne / belongsTo.
 */
final class ActiveRecordTest extends TestCase
{
    private \PDO $pdo;
    private Database $database;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, label TEXT)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role_id INTEGER)');
        $this->pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total INTEGER)');
        $this->pdo->exec("INSERT INTO roles (id, label) VALUES (1, 'admin'), (2, 'member')");
        $this->pdo->exec("INSERT INTO users (id, email, role_id) VALUES
            (1, 'a@example.com', 1),
            (2, 'b@example.com', 2),
            (3, 'c@example.com', 2)
        ");
        $this->pdo->exec("INSERT INTO orders (user_id, total) VALUES
            (1, 100),
            (1, 200),
            (2, 50)
        ");

        $this->database = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new \ReflectionObject($this->database))
            ->getProperty('connection')->setValue($this->database, $this->pdo);
    }

    public function testFindReturnsHydratedInstance(): void
    {
        $user = ARUser::find($this->database, 1);
        $this->assertInstanceOf(ARUser::class, $user);
        $this->assertSame(1, $user->id);
        $this->assertSame('a@example.com', $user->email);
        $this->assertSame(1, $user->role_id);
    }

    public function testFindMissReturnsNull(): void
    {
        $this->assertNull(ARUser::find($this->database, 999));
    }

    public function testIssetReflectsHydratedAttributes(): void
    {
        $user = ARUser::find($this->database, 1);
        $this->assertTrue(isset($user->email));
        $this->assertFalse(isset($user->no_such_column));
    }

    public function testMissingAttributeReturnsNull(): void
    {
        $user = ARUser::find($this->database, 1);
        $this->assertNull($user->no_such_column);
    }

    public function testHasManyFetchesRelatedRows(): void
    {
        $user   = ARUser::find($this->database, 1);
        $orders = $this->database->run($user->orders());

        $this->assertCount(2, $orders);
        $totals = array_map(fn ($o) => $o['total'], $orders);
        sort($totals);
        $this->assertSame([100, 200], $totals);
    }

    public function testHasManyHydrates(): void
    {
        $user       = ARUser::find($this->database, 1);
        $orderRows  = $this->database->run($user->orders());
        $orders     = ActiveRecord::hydrate($orderRows, AROrder::class);

        $this->assertCount(2, $orders);
        $this->assertInstanceOf(AROrder::class, $orders[0]);
        $this->assertSame(1, $orders[0]->user_id);
    }

    public function testHasOneLimitsToOneRow(): void
    {
        $user  = ARUser::find($this->database, 1);
        $first = $this->database->run($user->firstOrder());
        $this->assertCount(1, $first);
    }

    public function testBelongsToFetchesOwnerRow(): void
    {
        $user = ARUser::find($this->database, 1);
        $role = $this->database->run($user->role());

        $this->assertCount(1, $role);
        $this->assertSame(['id' => 1, 'label' => 'admin'], $role[0]);
    }

    public function testQueryBuilderIsComposable(): void
    {
        $user       = ARUser::find($this->database, 1);
        $highValue  = $this->database->run($user->orders()->andWhere('total', '>=', 150));
        $this->assertCount(1, $highValue);
        $this->assertSame(200, $highValue[0]['total']);
    }

    public function testStaticQueryScopesSubsequentBuilders(): void
    {
        $members = $this->database->run(
            ARUser::query()->where('role_id', '=', 2)->orderBy('id')
        );
        $this->assertSame(
            [['id' => 2, 'email' => 'b@example.com', 'role_id' => 2],
             ['id' => 3, 'email' => 'c@example.com', 'role_id' => 2]],
            $members
        );
    }

    public function testHydrateRejectsNonSubclass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ActiveRecord::hydrate([['id' => 1]], \stdClass::class);
    }

    public function testTableConstantMustBeDefined(): void
    {
        $this->expectException(\LogicException::class);
        NoTable::find($this->database, 1);
    }
}

class ARUser extends ActiveRecord
{
    public const TABLE = 'users';

    public function orders(): \Rxn\Orm\Builder\Query
    {
        return $this->hasMany(AROrder::class, 'user_id');
    }

    public function firstOrder(): \Rxn\Orm\Builder\Query
    {
        return $this->hasOne(AROrder::class, 'user_id');
    }

    public function role(): \Rxn\Orm\Builder\Query
    {
        return $this->belongsTo(ARRole::class, 'role_id');
    }
}

class AROrder extends ActiveRecord
{
    public const TABLE = 'orders';
}

class ARRole extends ActiveRecord
{
    public const TABLE = 'roles';
}

class NoTable extends ActiveRecord {}
