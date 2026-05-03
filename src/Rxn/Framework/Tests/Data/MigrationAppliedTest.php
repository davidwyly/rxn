<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Data;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Database;
use Rxn\Framework\Data\Migration;
use Rxn\Framework\Error\QueryException;

/**
 * Integration + unit tests for Migration::applied() missing-table handling.
 *
 * Three distinct exception paths are exercised:
 *
 *  1. Real SQLite in-memory PDO (no rxn_migrations table).
 *     Database::fetchAll → Query::execute() → QueryException("PDO Exception (no such table: …)", 500).
 *     The QueryException has no previous exception; the 'no such table' text is in the message.
 *
 *  2. Simulated Query::prepare() wrapping.
 *     Query::prepare() produces QueryException("PDO Exception (code 42S02)", 500, $pdoEx).
 *     The SQLSTATE is in the wrapper's *message*, not in its code (which is 500).
 *     A previous \PDOException with code '42S02' is also present.
 *
 *  3. Direct \PDOException with SQLSTATE code '42S02' (e.g. raw PDO call without the Query layer).
 *
 *  4. An unrelated QueryException must be rethrown, not swallowed.
 */
final class MigrationAppliedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rxn-migration-applied-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeDatabaseWithConnection(\PDO $pdo): Database
    {
        $database = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new \ReflectionObject($database))
            ->getProperty('connection')
            ->setValue($database, $pdo);
        return $database;
    }

    private function makeMockDatabaseThrowing(\Throwable $exception): Database
    {
        $mock = $this->createMock(Database::class);
        $mock->method('fetchAll')->willThrowException($exception);
        return $mock;
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    /**
     * Path 1: real SQLite, execute()-time failure.
     * Query::execute() wraps the PDO error in QueryException without a previous exception;
     * the 'no such table' text appears in the QueryException message.
     */
    public function testAppliedReturnsEmptyWhenTableMissingViaSqliteExecutePath(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Intentionally do NOT create rxn_migrations table.

        $database  = $this->makeDatabaseWithConnection($pdo);
        $migration = new Migration($database, $this->dir, false);

        $this->assertSame([], $migration->applied());
    }

    /**
     * Path 2: QueryException whose message contains "42S02" (mimics Query::prepare() wrapping).
     * Query::prepare() builds the message as "PDO Exception (code <SQLSTATE>)", so '42S02'
     * is in the message but the QueryException code itself is 500.
     * The PDOException previous deliberately has no SQLSTATE set; this test verifies that
     * message-based detection alone is sufficient.
     */
    public function testAppliedReturnsEmptyForPrepareStyleQueryException(): void
    {
        $pdoException   = new \PDOException('Base table or view not found');
        // No SQLSTATE code on the previous exception — detection relies on the message '42S02'.
        $queryException = new QueryException('PDO Exception (code 42S02)', 500, $pdoException);

        $database  = $this->makeMockDatabaseThrowing($queryException);
        $migration = new Migration($database, $this->dir, false);

        $this->assertSame([], $migration->applied());
    }

    /**
     * Path 2b: QueryException whose *previous* exception has SQLSTATE code '42S02'.
     * This covers the case where Query::prepare() preserves the original PDOException.
     */
    public function testAppliedReturnsEmptyWhenPreviousExceptionHas42S02Code(): void
    {
        $pdoException = new \PDOException('Base table or view not found');
        // \PDOException stores SQLSTATE in the code property (as a string).
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($pdoException, '42S02');

        $queryException = new QueryException('PDO Exception (code 42S02)', 500, $pdoException);

        $database  = $this->makeMockDatabaseThrowing($queryException);
        $migration = new Migration($database, $this->dir, false);

        $this->assertSame([], $migration->applied());
    }

    /**
     * Path 3: direct \PDOException with SQLSTATE code '42S02'.
     * This simulates a raw PDO call (not going through the Query layer).
     */
    public function testAppliedReturnsEmptyForDirectPdoExceptionWithMissingTableCode(): void
    {
        $pdoException = new \PDOException('Base table or view not found');
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($pdoException, '42S02');

        $database  = $this->makeMockDatabaseThrowing($pdoException);
        $migration = new Migration($database, $this->dir, false);

        $this->assertSame([], $migration->applied());
    }

    /**
     * Path 4: an unrelated QueryException must be rethrown, not silently swallowed.
     */
    public function testAppliedRethrowsUnrelatedQueryException(): void
    {
        $unrelated = new QueryException('Connection timed out', 500);

        $database  = $this->makeMockDatabaseThrowing($unrelated);
        $migration = new Migration($database, $this->dir, false);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Connection timed out');
        $migration->applied();
    }
}
