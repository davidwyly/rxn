<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Utility;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Utility\Scheduler;

final class SchedulerTest extends TestCase
{
    private string $dir;
    private string $state;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/rxn-sch-test-' . bin2hex(random_bytes(4));
        $this->state = $this->dir . '/scheduler.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->state)) {
            unlink($this->state);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testIntervalJobRunsFirstTimeAndRespectsInterval(): void
    {
        $runs      = 0;
        $scheduler = new Scheduler($this->state);
        $scheduler->every(60, 'heartbeat', function () use (&$runs) { $runs++; });

        $this->assertSame(['heartbeat'], $scheduler->run(now: 1000));
        $this->assertSame(1, $runs);

        // Second tick within the interval should not re-run.
        $this->assertSame([], $scheduler->run(now: 1030));
        $this->assertSame(1, $runs);

        // Tick past the interval window fires again.
        $this->assertSame(['heartbeat'], $scheduler->run(now: 1061));
        $this->assertSame(2, $runs);
    }

    public function testStatePersistsAcrossSchedulerInstances(): void
    {
        $runs = 0;
        $task = function () use (&$runs) { $runs++; };

        $first = new Scheduler($this->state);
        $first->every(60, 'job', $task);
        $first->run(now: 2000);
        $this->assertSame(1, $runs);

        // Fresh Scheduler must pick up the persisted last-run time.
        $second = new Scheduler($this->state);
        $second->every(60, 'job', $task);
        $this->assertSame([], $second->run(now: 2030));
        $this->assertSame(1, $runs);

        $this->assertSame(['job'], $second->run(now: 2061));
        $this->assertSame(2, $runs);
    }

    public function testAtUsesCustomPredicate(): void
    {
        $runs      = 0;
        $scheduler = new Scheduler($this->state);
        $scheduler->at(
            fn (int $now) => $now % 10 === 0,
            'tens',
            function () use (&$runs) { $runs++; }
        );

        $scheduler->run(now: 101);
        $scheduler->run(now: 110);
        $scheduler->run(now: 119);
        $scheduler->run(now: 120);

        $this->assertSame(2, $runs);
    }

    public function testIntervalRejectsZero(): void
    {
        $scheduler = new Scheduler($this->state);
        $this->expectException(\InvalidArgumentException::class);
        $scheduler->every(0, 'bad', fn () => null);
    }
}
