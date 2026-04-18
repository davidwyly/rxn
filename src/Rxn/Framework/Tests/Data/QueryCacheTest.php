<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Data;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Query;

/**
 * Integration test for the filesystem-based query cache. Uses an
 * in-memory sqlite PDO so no MySQL is required.
 */
final class QueryCacheTest extends TestCase
{
    private \PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE kv (k TEXT PRIMARY KEY, v TEXT)');
        $this->pdo->exec("INSERT INTO kv (k, v) VALUES ('greeting', 'hello')");

        $this->dir = sys_get_temp_dir() . '/rxn-qcache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->dir);
        }
    }

    public function testFetchPopulatesCacheAndSubsequentQueryHitsIt(): void
    {
        $sql = 'SELECT v FROM kv WHERE k = :k';

        $first = new Query($this->pdo, Query::TYPE_FETCH, $sql, ['k' => 'greeting']);
        $first->setCache($this->dir, 60);
        $this->assertSame(['v' => 'hello'], $first->run());

        $cached = glob($this->dir . '/*.qcache') ?: [];
        $this->assertCount(1, $cached, 'first run should have written exactly one cache file');

        // Mutate the underlying DB so a cache hit is distinguishable
        // from a live query.
        $this->pdo->exec("UPDATE kv SET v = 'changed' WHERE k = 'greeting'");

        $second = new Query($this->pdo, Query::TYPE_FETCH, $sql, ['k' => 'greeting']);
        $second->setCache($this->dir, 60);
        $this->assertSame(
            ['v' => 'hello'],
            $second->run(),
            'second Query should have returned the cached result, not the mutated row'
        );
    }

    public function testExpiredEntryIsRefetched(): void
    {
        $sql   = 'SELECT v FROM kv WHERE k = :k';
        $first = new Query($this->pdo, Query::TYPE_FETCH, $sql, ['k' => 'greeting']);
        $first->setCache($this->dir, 1);
        $first->run();

        $cacheFile = (glob($this->dir . '/*.qcache') ?: [])[0] ?? null;
        $this->assertNotNull($cacheFile);

        // Backdate the cache file past the TTL so the next run must miss.
        touch($cacheFile, time() - 10);

        $this->pdo->exec("UPDATE kv SET v = 'fresh' WHERE k = 'greeting'");

        $second = new Query($this->pdo, Query::TYPE_FETCH, $sql, ['k' => 'greeting']);
        $second->setCache($this->dir, 1);
        $this->assertSame(['v' => 'fresh'], $second->run());
    }

    public function testDifferentBindingsProduceDifferentCacheKeys(): void
    {
        $this->pdo->exec("INSERT INTO kv (k, v) VALUES ('farewell', 'bye')");
        $sql = 'SELECT v FROM kv WHERE k = :k';

        (new Query($this->pdo, Query::TYPE_FETCH, $sql, ['k' => 'greeting']))
            ->setCache($this->dir, 60);
        $q1 = new Query($this->pdo, Query::TYPE_FETCH, $sql, ['k' => 'greeting']);
        $q1->setCache($this->dir, 60);
        $q1->run();

        $q2 = new Query($this->pdo, Query::TYPE_FETCH, $sql, ['k' => 'farewell']);
        $q2->setCache($this->dir, 60);
        $q2->run();

        $this->assertCount(2, glob($this->dir . '/*.qcache') ?: []);
    }

    public function testWriteQueriesAreNotCached(): void
    {
        $q = new Query(
            $this->pdo,
            Query::TYPE_QUERY,
            "INSERT INTO kv (k, v) VALUES ('one', 'two')"
        );
        $q->setCache($this->dir, 60);
        $q->run();

        $this->assertSame([], glob($this->dir . '/*.qcache') ?: []);
    }

    public function testClearCacheRemovesAllEntries(): void
    {
        $sql = 'SELECT v FROM kv WHERE k = :k';
        $q   = new Query($this->pdo, Query::TYPE_FETCH, $sql, ['k' => 'greeting']);
        $q->setCache($this->dir, 60);
        $q->run();
        $this->assertCount(1, glob($this->dir . '/*.qcache') ?: []);

        $q->clearCache();
        $this->assertSame([], glob($this->dir . '/*.qcache') ?: []);
    }
}
