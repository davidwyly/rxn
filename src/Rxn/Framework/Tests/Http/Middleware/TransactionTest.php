<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Data\Database;
use Rxn\Framework\Http\Middleware\Transaction;

/**
 * Uses an in-memory PDO sqlite connection wired into a real
 * Database instance — exercises the actual transaction lifecycle
 * (BEGIN / COMMIT / ROLLBACK) instead of mocking it. Catches
 * regressions where Database's internal `transaction_depth`
 * counter gets out of sync.
 */
final class TransactionTest extends TestCase
{
    private function request(string $method): ServerRequestInterface
    {
        return new ServerRequest($method, 'http://test.local/');
    }

    private function terminal(callable $cb): RequestHandlerInterface
    {
        return new class($cb) implements RequestHandlerInterface {
            /** @var callable */
            private $cb;
            public function __construct(callable $cb) { $this->cb = $cb; }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->cb)($request);
            }
        };
    }

    public function testGetRequestPassesThroughWithoutTransaction(): void
    {
        [$db, $pdo] = $this->makeDatabase();

        $response = (new Transaction($db))->process(
            $this->request('GET'),
            $this->terminal(fn () => new Psr7Response(200)),
        );

        $this->assertFalse($pdo->inTransaction(), 'GET should not open a transaction');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSuccessful2xxCommits(): void
    {
        [$db, $pdo] = $this->makeDatabase();

        $insertedDuringRequest = null;
        $response = (new Transaction($db))->process(
            $this->request('POST'),
            $this->terminal(function () use ($pdo, &$insertedDuringRequest) {
                $pdo->exec("INSERT INTO widgets (name) VALUES ('committed')");
                $insertedDuringRequest = $pdo->inTransaction();
                return new Psr7Response(201);
            }),
        );

        $this->assertTrue($insertedDuringRequest, 'transaction should be open during handler');
        $this->assertFalse($pdo->inTransaction(), 'transaction should be closed after handler');
        $this->assertSame(201, $response->getStatusCode());
        $count = $pdo->query("SELECT COUNT(*) FROM widgets WHERE name = 'committed'")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }

    public function testClientError4xxRollsBack(): void
    {
        [$db, $pdo] = $this->makeDatabase();

        $response = (new Transaction($db))->process(
            $this->request('POST'),
            $this->terminal(function () use ($pdo) {
                $pdo->exec("INSERT INTO widgets (name) VALUES ('should rollback')");
                return new Psr7Response(422);
            }),
        );

        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(422, $response->getStatusCode());
        $count = $pdo->query("SELECT COUNT(*) FROM widgets WHERE name = 'should rollback'")->fetchColumn();
        $this->assertSame(0, (int)$count, 'rollback should have removed the partial write');
    }

    public function testServerError5xxRollsBack(): void
    {
        [$db, $pdo] = $this->makeDatabase();

        (new Transaction($db))->process(
            $this->request('POST'),
            $this->terminal(function () use ($pdo) {
                $pdo->exec("INSERT INTO widgets (name) VALUES ('5xx rollback')");
                return new Psr7Response(503);
            }),
        );

        $count = $pdo->query("SELECT COUNT(*) FROM widgets WHERE name = '5xx rollback'")->fetchColumn();
        $this->assertSame(0, (int)$count);
    }

    public function testThrownExceptionRollsBackAndRethrows(): void
    {
        [$db, $pdo] = $this->makeDatabase();

        $caught = null;
        try {
            (new Transaction($db))->process(
                $this->request('POST'),
                $this->terminal(function () use ($pdo) {
                    $pdo->exec("INSERT INTO widgets (name) VALUES ('exception rollback')");
                    throw new \RuntimeException('controller blew up');
                }),
            );
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

        // Apps that want read-side snapshot isolation can opt GETs in.
        $duringHandler = null;
        (new Transaction($db, wrappedMethods: ['GET', 'POST']))->process(
            $this->request('GET'),
            $this->terminal(function () use ($pdo, &$duringHandler) {
                $duringHandler = $pdo->inTransaction();
                return new Psr7Response(200);
            }),
        );

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
}
