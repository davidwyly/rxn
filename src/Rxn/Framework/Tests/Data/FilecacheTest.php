<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Data;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Config;
use Rxn\Framework\Data\Filecache;

/**
 * Filecache resolves its on-disk root as
 * `Filecache.php __DIR__ . '/' . $config->fileCacheDirectory`,
 * so the test root is expressed *relative* to `src/Rxn/Framework/Data/`
 * — climbing one level out and back into `Tests/Data/_fctmp_<rand>`.
 */
final class FilecacheTest extends TestCase
{
    private string $tmpName;
    private string $tmpAbs;
    private Filecache $cache;

    protected function setUp(): void
    {
        $this->tmpName = '_fctmp_' . bin2hex(random_bytes(4));
        $this->tmpAbs  = __DIR__ . '/' . $this->tmpName;
        if (!mkdir($this->tmpAbs, 0770, true) && !is_dir($this->tmpAbs)) {
            $this->fail("Failed to create cache root '$this->tmpAbs'");
        }

        $config = new Config();
        // Relative to Filecache.php's __DIR__ (.../Framework/Data/),
        // climb out into Tests/Data/<tmpName>.
        $config->fileCacheDirectory = '../Tests/Data/' . $this->tmpName;
        $this->cache = new Filecache($config);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmpAbs);
    }

    public function testRoundTripStoresAndRecoversObject(): void
    {
        $payload = new FilecachePayload(['hello' => 'world', 'n' => 42]);

        $this->assertFalse($this->cache->isClassCached(FilecachePayload::class, ['k' => 1]));

        $this->cache->cacheObject($payload, ['k' => 1]);

        $this->assertTrue($this->cache->isClassCached(FilecachePayload::class, ['k' => 1]));
        $hit = $this->cache->getObject(FilecachePayload::class, ['k' => 1]);
        $this->assertInstanceOf(FilecachePayload::class, $hit);
        $this->assertSame(['hello' => 'world', 'n' => 42], $hit->data);
    }

    public function testGetObjectReturnsFalseForMissingEntry(): void
    {
        $this->assertFalse(
            $this->cache->getObject(FilecachePayload::class, ['nope' => true])
        );
    }

    public function testParameterHashIsolatesCacheEntries(): void
    {
        $a = new FilecachePayload(['v' => 'a']);
        $b = new FilecachePayload(['v' => 'b']);

        $this->cache->cacheObject($a, ['k' => 'a']);
        $this->cache->cacheObject($b, ['k' => 'b']);

        $hitA = $this->cache->getObject(FilecachePayload::class, ['k' => 'a']);
        $hitB = $this->cache->getObject(FilecachePayload::class, ['k' => 'b']);
        $this->assertSame('a', $hitA->data['v']);
        $this->assertSame('b', $hitB->data['v']);
    }

    public function testCachedFileLandsUnderClassShortName(): void
    {
        $this->cache->cacheObject(new FilecachePayload(['x' => 1]), ['k' => 1]);

        $bucket = $this->tmpAbs . '/FilecachePayload';
        $this->assertDirectoryExists($bucket);
        $files = glob($bucket . '/*.filecache') ?: [];
        $this->assertCount(1, $files);
    }

    public function testRejectsUnknownClassOnRead(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Invalid class name");
        $this->cache->getObject('No\\Such\\Class', ['k' => 1]);
    }

    public function testRejectsUnknownClassOnIsCached(): void
    {
        $this->expectException(\Exception::class);
        $this->cache->isClassCached('No\\Such\\Class', ['k' => 1]);
    }

    public function testMissingRootDirectoryIsRejectedAtConstruction(): void
    {
        $config = new Config();
        $config->fileCacheDirectory = '../Tests/Data/_fctmp_does_not_exist_' . bin2hex(random_bytes(4));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("doesn't exist");
        new Filecache($config);
    }

    private function rrm(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $this->rrm($path . '/' . $entry);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }
}

/**
 * Plain serializable payload for round-tripping through Filecache.
 */
final class FilecachePayload
{
    public function __construct(public array $data) {}
}
