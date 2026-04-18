<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Data;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Migration;

final class MigrationDiscoveryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rxn-migration-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testReturnsSortedSqlFiles(): void
    {
        touch($this->dir . '/0002_second.sql');
        touch($this->dir . '/0001_first.sql');
        touch($this->dir . '/0003_third.sql');

        $this->assertSame(
            ['0001_first.sql', '0002_second.sql', '0003_third.sql'],
            Migration::discoverMigrations($this->dir)
        );
    }

    public function testIgnoresNonSqlFiles(): void
    {
        touch($this->dir . '/0001_first.sql');
        touch($this->dir . '/readme.md');
        touch($this->dir . '/notes.txt');

        $this->assertSame(
            ['0001_first.sql'],
            Migration::discoverMigrations($this->dir)
        );
    }

    public function testEmptyDirectoryReturnsEmptyArray(): void
    {
        $this->assertSame([], Migration::discoverMigrations($this->dir));
    }

    public function testTrailingSlashIsTolerated(): void
    {
        touch($this->dir . '/0001_first.sql');
        $this->assertSame(
            ['0001_first.sql'],
            Migration::discoverMigrations($this->dir . '/')
        );
    }
}
