<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Data;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Database;
use Rxn\Orm\Builder\Query;

/**
 * Integration test: feed a Builder\Query into Database::run and
 * exercise the whole toSql -> bind -> execute round-trip against
 * an in-memory sqlite PDO.
 */
final class DatabaseRunTest extends TestCase
{
    private \PDO $pdo;
    private Database $database;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role TEXT, active INTEGER)');
        $this->pdo->exec("INSERT INTO users (email, role, active) VALUES
            ('a@example.com', 'admin',  1),
            ('b@example.com', 'member', 1),
            ('c@example.com', 'member', 0),
            ('d@example.com', 'owner',  1)
        ");

        // Build a Database without calling its env-driven constructor.
        $this->database = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new \ReflectionObject($this->database))
            ->getProperty('connection')->setValue($this->database, $this->pdo);
    }

    public function testRunExecutesASelectBuiltViaQuery(): void
    {
        $query = (new Query())
            ->select(['id', 'email'])
            ->from('users')
            ->where('active', '=', 1)
            ->andWhereIn('role', ['admin', 'owner'])
            ->orderBy('id');

        $rows = $this->database->run($query);

        $this->assertSame([
            ['id' => 1, 'email' => 'a@example.com'],
            ['id' => 4, 'email' => 'd@example.com'],
        ], $rows);
    }

    public function testRunRespectsLimitAndOffset(): void
    {
        $query = (new Query())
            ->select(['id'])
            ->from('users')
            ->orderBy('id')
            ->limit(2)
            ->offset(1);

        $this->assertSame(
            [['id' => 2], ['id' => 3]],
            $this->database->run($query)
        );
    }

    public function testRunHandlesNestedGroupedWheres(): void
    {
        $query = (new Query())
            ->select(['id'])
            ->from('users')
            ->where('active', '=', 1)
            ->andWhere('role', '=', 'admin', function (Query $w) {
                $w->orWhere('role', '=', 'owner');
            })
            ->orderBy('id');

        $this->assertSame(
            [['id' => 1], ['id' => 4]],
            $this->database->run($query)
        );
    }

    public function testRunExecutesInsertUpdateDelete(): void
    {
        $insert = (new \Rxn\Orm\Builder\Insert())
            ->into('users')
            ->row(['email' => 'e@example.com', 'role' => 'member', 'active' => 1])
            ->row(['email' => 'f@example.com', 'role' => 'guest',  'active' => 0]);
        $this->assertTrue($this->database->run($insert));

        $rows = $this->pdo->query("SELECT email, role, active FROM users WHERE email LIKE '%@example.com' AND id > 4 ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(
            [
                ['email' => 'e@example.com', 'role' => 'member', 'active' => 1],
                ['email' => 'f@example.com', 'role' => 'guest',  'active' => 0],
            ],
            $rows
        );

        $update = (new \Rxn\Orm\Builder\Update())
            ->table('users')
            ->set(['role' => 'member', 'active' => 1])
            ->where('role', '=', 'guest');
        $this->assertTrue($this->database->run($update));

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'guest'")->fetchColumn();
        $this->assertSame(0, $count);

        $delete = (new \Rxn\Orm\Builder\Delete())
            ->from('users')
            ->where('email', '=', 'e@example.com');
        $this->assertTrue($this->database->run($delete));

        $remaining = (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE email = 'e@example.com'")->fetchColumn();
        $this->assertSame(0, $remaining);
    }
}
