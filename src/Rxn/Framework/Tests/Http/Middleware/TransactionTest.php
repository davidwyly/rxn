<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Database;
use Rxn\Framework\Http\Middleware\Transaction;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

/**
 * Uses an in-memory PDO sqlite connection wired into a real
 * Database instance — exercises the actual transaction lifecycle
 * (BEGIN / COMMIT / ROLLBACK) instead of mocking it. Catches
 * regressions where Database's internal `transaction_depth`
 * counter gets out of sync.
 */
final class TransactionTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testGetRequestPassesThroughWithoutTransaction(): void
    {
        [$db, $pdo] = $this->makeDatabase();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $mw = new Transaction($db);
        $response = $mw->handle($this->bareRequest(), fn () => $this->okResponse(200));

        $this->assertFalse($pdo->inTransaction(), 'GET should not open a transaction');
        $this->assertSame(200, $response->getCode());
    }

    public function testSuccessful2xxCommits(): void
    {
        [$db, $pdo] = $this->makeDatabase();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $insertedDuringRequest = null;
        $mw = new Transaction($db);
        $response = $mw->handle($this->bareRequest(), function () use ($pdo, &$insertedDuringRequest) {
            $pdo->exec("INSERT INTO widgets (name) VALUES ('committed')");
            $insertedDuringRequest = $pdo->inTransaction();
            return $this->okResponse(201);
        });

        $this->assertTrue($insertedDuringRequest, 'transaction should be open during handler');
        $this->assertFalse($pdo->inTransaction(), 'transaction should be closed after handler');
        $this->assertSame(201, $response->getCode());
        // Row survived the commit
        $count = $pdo->query("SELECT COUNT(*) FROM widgets WHERE name = 'committed'")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }

    public function testClientError4xxRollsBack(): void
    {
        [$db, $pdo] = $this->makeDatabase();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $mw = new Transaction($db);
        $response = $mw->handle($this->bareRequest(), function () use ($pdo) {
            $pdo->exec("INSERT INTO widgets (name) VALUES ('should rollback')");
            return $this->okResponse(422);
        });

        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(422, $response->getCode());
        $count = $pdo->query("SELECT COUNT(*) FROM widgets WHERE name = 'should rollback'")->fetchColumn();
        $this->assertSame(0, (int)$count, 'rollback should have removed the partial write');
    }

    public function testServerError5xxRollsBack(): void
    {
        [$db, $pdo] = $this->makeDatabase();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $mw = new Transaction($db);
        $mw->handle($this->bareRequest(), function () use ($pdo) {
            $pdo->exec("INSERT INTO widgets (name) VALUES ('5xx rollback')");
            return $this->okResponse(503);
        });

        $count = $pdo->query("SELECT COUNT(*) FROM widgets WHERE name = '5xx rollback'")->fetchColumn();
        $this->assertSame(0, (int)$count);
    }

    public function testThrownExceptionRollsBackAndRethrows(): void
    {
        [$db, $pdo] = $this->makeDatabase();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $mw = new Transaction($db);
        $caught = null;
        try {
            $mw->handle($this->bareRequest(), function () use ($pdo) {
                $pdo->exec("INSERT INTO widgets (name) VALUES ('exception rollback')");
                throw new \RuntimeException('controller blew up');
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'middleware must re-throw the original exception');
        $this->assertSame('controller blew up', $caught->getMessage());
        $this->assertFalse($pdo->inTransaction());
        $count = $pdo->query("SELECT COUNT(*) FROM widgets WHERE name = 'exception rollback'")->fetchColumn();
        $this->assertSame(0, (int)$count);
    }

    public function testCustomMethodList(): void
    {
        [$db, $pdo] = $this->makeDatabase();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Apps that want read-side snapshot isolation can opt GETs in.
        $mw = new Transaction($db, wrappedMethods: ['GET', 'POST']);
        $duringHandler = null;
        $mw->handle($this->bareRequest(), function () use ($pdo, &$duringHandler) {
            $duringHandler = $pdo->inTransaction();
            return $this->okResponse(200);
        });

        $this->assertTrue($duringHandler);
        $this->assertFalse($pdo->inTransaction());
    }

    /** @return array{0: Database, 1: \PDO} */
    private function makeDatabase(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (name TEXT)');

        // Build a Database and inject the PDO via the existing
        // public connect() entry point — bypasses the env-var
        // configuration the constructor expects.
        $db = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $db->connect($pdo);
        return [$db, $pdo];
    }

    private function bareRequest(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }

    private function okResponse(int $code): Response
    {
        $r = (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
        $codeProp = (new \ReflectionClass(Response::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($r, $code);
        return $r;
    }
}
