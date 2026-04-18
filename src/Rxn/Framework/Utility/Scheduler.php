<?php declare(strict_types=1);

namespace Rxn\Framework\Utility;

/**
 * Minimal in-process task scheduler. Register tasks with an
 * interval (or a custom `when(int $now): bool` predicate) and call
 * run() from a cron tick or a long-running worker; due tasks fire
 * and their last-run times are persisted to a JSON state file so
 * the scheduler is safe across process restarts.
 *
 *   $scheduler = new Scheduler('/var/lib/rxn/scheduler.json');
 *   $scheduler->every(60, 'purge-cache', fn () => $cache->clearCache());
 *   $scheduler->at(fn(int $now) => (int)date('G', $now) === 3, 'nightly-report', $reportJob);
 *   $ran = $scheduler->run();
 */
class Scheduler
{
    private string $stateFile;

    /** @var array<string, array{when: callable, task: callable}> */
    private array $jobs = [];

    public function __construct(string $stateFile)
    {
        $directory = dirname($stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException("Scheduler state directory unavailable: $directory");
        }
        $this->stateFile = $stateFile;
    }

    /**
     * Register a task that should run at most once every $seconds.
     */
    public function every(int $seconds, string $name, callable $task): void
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Scheduler interval must be a positive integer');
        }
        $this->jobs[$name] = [
            'when' => function (int $now, ?int $lastRun) use ($seconds): bool {
                return $lastRun === null || ($now - $lastRun) >= $seconds;
            },
            'task' => $task,
        ];
    }

    /**
     * Register a task with an arbitrary due-time predicate. The
     * closure receives the current unix timestamp and the task's
     * last-run timestamp (null if it has never run) and returns true
     * when the task is due.
     */
    public function at(callable $when, string $name, callable $task): void
    {
        $this->jobs[$name] = ['when' => $when, 'task' => $task];
    }

    /**
     * Run every registered task whose `when` predicate returns true.
     * Returns the names of tasks that fired this tick.
     *
     * @return string[]
     */
    public function run(?int $now = null): array
    {
        $now   = $now ?? time();
        $state = $this->readState();
        $ran   = [];

        foreach ($this->jobs as $name => $job) {
            $lastRun = $state[$name] ?? null;
            if (($job['when'])($now, $lastRun)) {
                ($job['task'])();
                $state[$name] = $now;
                $ran[]        = $name;
            }
        }

        if ($ran !== []) {
            $this->writeState($state);
        }
        return $ran;
    }

    /**
     * @return array<string, int>
     */
    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $raw = file_get_contents($this->stateFile);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, int> $state
     */
    private function writeState(array $state): void
    {
        $tmp = tempnam(dirname($this->stateFile), 'sch_');
        if ($tmp === false) {
            return;
        }
        if (file_put_contents($tmp, (string)json_encode($state), LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
        }
    }
}
