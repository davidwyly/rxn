<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Utility;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Utility\Logger;

final class LoggerTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/rxn-logger-test-' . bin2hex(random_bytes(4));
        $this->file = $this->dir . '/app.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testLogWritesOneJsonLinePerEntry(): void
    {
        $log = new Logger($this->file);
        $log->info('hello', ['request_id' => 'abc123']);
        $log->warning('slow query', ['ms' => 420]);

        $lines = array_values(array_filter(explode("\n", file_get_contents($this->file))));
        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $this->assertSame('info', $first['level']);
        $this->assertSame('hello', $first['message']);
        $this->assertSame(['request_id' => 'abc123'], $first['context']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['ts']);

        $second = json_decode($lines[1], true);
        $this->assertSame('warning', $second['level']);
        $this->assertSame(['ms' => 420], $second['context']);
    }

    public function testUnknownLevelIsRejected(): void
    {
        $log = new Logger($this->file);
        $this->expectException(\InvalidArgumentException::class);
        $log->log('verbose', 'nope');
    }

    public function testMissingDirectoryIsCreated(): void
    {
        $this->assertFalse(is_dir($this->dir));
        new Logger($this->file);
        $this->assertTrue(is_dir($this->dir));
    }

    public function testImplementsPsr3LoggerInterface(): void
    {
        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, new Logger($this->file));
    }

    public function testAcceptsStringableMessages(): void
    {
        $log = new Logger($this->file);
        $stringable = new class implements \Stringable {
            public function __toString(): string { return 'cast me'; }
        };
        $log->info($stringable, ['k' => 'v']);

        $line = json_decode(trim((string)file_get_contents($this->file)), true);
        $this->assertSame('cast me', $line['message']);
    }

    public function testAllPsr3LevelMethodsRouteThroughLog(): void
    {
        // PSR-3's LoggerTrait routes every level-specific method
        // through log($level, ...). Confirm the eight constants
        // round-trip through our log() — catches a regression where
        // the trait stops dispatching.
        $log = new Logger($this->file);
        foreach (\Rxn\Framework\Utility\Logger::LEVELS as $level) {
            $log->{$level}("msg-$level");
        }
        $lines = array_values(array_filter(explode("\n", (string)file_get_contents($this->file))));
        $this->assertCount(count(\Rxn\Framework\Utility\Logger::LEVELS), $lines);
        foreach (\Rxn\Framework\Utility\Logger::LEVELS as $i => $level) {
            $decoded = json_decode($lines[$i], true);
            $this->assertSame($level, $decoded['level']);
        }
    }
}
