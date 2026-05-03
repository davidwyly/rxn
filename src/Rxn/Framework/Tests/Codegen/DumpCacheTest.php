<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\DumpCache;

final class DumpCacheTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rxn-dumpcache-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0770, true);
    }

    protected function tearDown(): void
    {
        DumpCache::useDir(null);
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    public function testDirIsNullByDefault(): void
    {
        DumpCache::useDir(null);
        $this->assertNull(DumpCache::dir());
    }

    public function testUseDirRejectsMissingPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DumpCache::useDir('/definitely/not/a/real/path/' . bin2hex(random_bytes(4)));
    }

    public function testLoadReturnsNullWhenDirNotConfigured(): void
    {
        DumpCache::useDir(null);
        $this->assertNull(DumpCache::load('return 42;'));
    }

    public function testLoadWritesFileAndReturnsRequireResult(): void
    {
        DumpCache::useDir($this->dir);
        $result = DumpCache::load('return 42;');
        $this->assertSame(42, $result);
        $files = glob($this->dir . '/*.php') ?: [];
        $this->assertCount(1, $files);
    }

    public function testLoadIsContentAddressed(): void
    {
        DumpCache::useDir($this->dir);
        DumpCache::load('return 1;');
        DumpCache::load('return 1;');   // identical → same file
        DumpCache::load('return 2;');   // different → new file
        $files = glob($this->dir . '/*.php') ?: [];
        $this->assertCount(2, $files);
    }

    public function testLoadDoesNotRewriteExistingFile(): void
    {
        DumpCache::useDir($this->dir);
        DumpCache::load('return 7;');
        $files1 = glob($this->dir . '/*.php') ?: [];
        $this->assertCount(1, $files1);
        $mtime = filemtime($files1[0]);

        clearstatcache();
        DumpCache::load('return 7;');
        $files2 = glob($this->dir . '/*.php') ?: [];
        $this->assertSame($files1, $files2);
        $this->assertSame($mtime, filemtime($files2[0]));
    }

    public function testPurgeFilesIsNoOpWhenNoDir(): void
    {
        DumpCache::useDir(null);
        DumpCache::purgeFiles();   // must not throw
        $this->expectNotToPerformAssertions();
    }

    public function testPurgeFilesDeletesAllPhpFilesInDir(): void
    {
        DumpCache::useDir($this->dir);
        DumpCache::load('return 1;');
        DumpCache::load('return 2;');
        $this->assertCount(2, glob($this->dir . '/*.php') ?: []);

        DumpCache::purgeFiles();
        $this->assertSame([], glob($this->dir . '/*.php') ?: []);
    }
}
