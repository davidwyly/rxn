<?php declare(strict_types=1);

/**
 * Fiber-await smoke bench. Boots three backend servers each
 * sleeping 100ms, then measures sequential vs fiber-parallel wall
 * clock for an N-call fan-out.
 *
 * Usage:
 *   php bench/fiber/run.php [iterations=20]
 *
 * Expected shape:
 *   sequential ≈ 300ms   (three 100ms calls in series)
 *   fiber      ≈ 100ms   (three 100ms calls overlapped)
 *   ratio      ≈ 3×
 */

require __DIR__ . '/../../vendor/autoload.php';

use Rxn\Framework\Concurrency\HttpClient;
use Rxn\Framework\Concurrency\Scheduler;
use function Rxn\Framework\Concurrency\awaitAll;

$iterations = (int) ($argv[1] ?? 20);
$fanout     = (int) ($argv[2] ?? 3);
$basePort   = 8101;
$ports      = [];
for ($i = 0; $i < $fanout; $i++) {
    $ports[] = $basePort + $i;
}

// Boot three backends.
$pids = [];
foreach ($ports as $port) {
    $pids[$port] = startBackend($port);
}
register_shutdown_function(function () use ($pids): void {
    foreach ($pids as $pid) {
        @posix_kill($pid, SIGTERM);
    }
});

waitForReady($ports);
echo "ready: " . implode(', ', array_map(fn ($p) => "127.0.0.1:$p", $ports)) . "\n";

// --------- sequential baseline ---------
$seqTimes = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    foreach ($ports as $port) {
        file_get_contents("http://127.0.0.1:$port/whatever");
    }
    $seqTimes[] = (hrtime(true) - $t0) / 1e6;   // ms
}

// --------- fiber-await ---------
$fibTimes = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $scheduler = new Scheduler();
    $client    = new HttpClient($scheduler);
    $scheduler->run(function () use ($client, $ports): array {
        $promises = [];
        foreach ($ports as $port) {
            $promises[$port] = $client->getAsync("http://127.0.0.1:$port/whatever");
        }
        return awaitAll($promises);
    });
    $fibTimes[] = (hrtime(true) - $t0) / 1e6;   // ms
}

sort($seqTimes);
sort($fibTimes);

$seqMedian = $seqTimes[(int) (count($seqTimes) / 2)];
$fibMedian = $fibTimes[(int) (count($fibTimes) / 2)];
$seqMin    = $seqTimes[0];
$fibMin    = $fibTimes[0];

printf("sequential: median %.1f ms (best %.1f ms) over %d runs\n", $seqMedian, $seqMin, $iterations);
printf("fiber-await: median %.1f ms (best %.1f ms) over %d runs\n", $fibMedian, $fibMin, $iterations);
printf("speedup: median %.2f×, best %.2f×\n", $seqMedian / $fibMedian, $seqMin / $fibMin);

// ---------- helpers ----------

function startBackend(int $port): int
{
    $cmd = sprintf(
        'php -S 127.0.0.1:%d %s/backend.php > /dev/null 2>&1 & echo $!',
        $port,
        escapeshellarg(__DIR__),
    );
    $out = shell_exec($cmd);
    return (int) trim($out ?? '0');
}

function waitForReady(array $ports): void
{
    $deadline = time() + 5;
    foreach ($ports as $port) {
        while (time() < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                fclose($sock);
                continue 2;
            }
            usleep(50_000);
        }
        fwrite(STDERR, "backend on $port did not come up\n");
        exit(1);
    }
}
