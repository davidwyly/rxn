<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Utility;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Utility\RateLimiter;

final class RateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rxn-rate-test-' . bin2hex(random_bytes(4));
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

    public function testAllowsUpToLimitThenBlocks(): void
    {
        $rl = new RateLimiter($this->dir, limit: 3, window: 60);
        $this->assertTrue($rl->allow('1.2.3.4', now: 100));
        $this->assertTrue($rl->allow('1.2.3.4', now: 101));
        $this->assertTrue($rl->allow('1.2.3.4', now: 102));
        $this->assertFalse($rl->allow('1.2.3.4', now: 103));
    }

    public function testSeparateKeysHaveSeparateBuckets(): void
    {
        $rl = new RateLimiter($this->dir, limit: 1, window: 60);
        $this->assertTrue($rl->allow('a', now: 100));
        $this->assertTrue($rl->allow('b', now: 100));
        $this->assertFalse($rl->allow('a', now: 101));
        $this->assertFalse($rl->allow('b', now: 101));
    }

    public function testWindowRollover(): void
    {
        $rl = new RateLimiter($this->dir, limit: 1, window: 10);
        $this->assertTrue($rl->allow('k', now: 100));
        $this->assertFalse($rl->allow('k', now: 105));
        $this->assertTrue($rl->allow('k', now: 111), 'counter should reset once $window seconds have elapsed');
    }

    public function testZeroOrNegativeConfigRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimiter($this->dir, limit: 0, window: 60);
    }
}
