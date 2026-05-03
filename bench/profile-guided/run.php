<?php declare(strict_types=1);

namespace Rxn\Bench\ProfileGuided;

/**
 * Bench for horizons.md theme 3.1 ship signal:
 *   "Set up a workload with 100 DTOs where 10 are hot; bench
 *    memory + first-request latency vs. unconditional dump.
 *    If memory drops meaningfully (>30%) and first-request
 *    latency stays stable, ship."
 *
 * Three modes measured per fresh PHP subprocess (clean state):
 *
 *   runtime-only        no DumpCache; every bind() walks reflection
 *   unconditional       DumpCache + compileFor() on all 100 DTOs
 *   profile-guided      DumpCache + compileFor() on top-10 hot DTOs only
 *
 * Per mode we measure:
 *   - boot.warm_ms    time spent inside warming (compileFor calls)
 *   - boot.peak_mem   PHP heap memory after warming
 *   - hot.ops_s       throughput on hot DTOs (the 10 the workload
 *                     hits 90% of the time)
 *   - cold.ops_s      throughput on cold DTOs (the 90 it rarely hits)
 *
 * Each subprocess prints a single JSON line. The parent
 * (bench/profile-guided/run.php with no --mode flag) orchestrates,
 * aggregates, and prints a comparison table.
 *
 * USAGE:
 *   php bench/profile-guided/run.php
 *   php bench/profile-guided/run.php --mode=runtime-only --json
 *
 * Caveats:
 *   - The 100 DTOs are generated at boot via eval, so they all live
 *     in PHP's class table regardless of mode. The memory delta we
 *     measure is the COMPILE-CACHE cost (closures + dumped sources),
 *     not the class-table cost. That's the right thing for
 *     profile-guided's claim ("opcache memory pays only for hot
 *     DTOs"): the unconditional mode caches 100 closures, profile-
 *     guided caches 10.
 *   - Opcache memory (separate from PHP heap) isn't measured directly
 *     — it scales with the dumped *.php count, which IS reported via
 *     boot.cache_files.
 *
 * Run on a quiet machine for cleanest numbers. Don't repurpose for
 * cross-framework comparisons; this is a within-framework A/B.
 */

const PROJECT_ROOT = __DIR__ . '/../..';
require_once PROJECT_ROOT . '/vendor/autoload.php';

use Rxn\Framework\Codegen\DumpCache;
use Rxn\Framework\Codegen\Profile\BindProfile;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\RequestDto;

const HOT_COUNT       = 10;
const COLD_COUNT      = 90;
const TOTAL_DTO_COUNT = HOT_COUNT + COLD_COUNT;
const WARMUP_S        = 0.05;
const RUN_S           = 0.25;
const PROFILE_TOP_K   = 10;

$opts   = parseFlags($argv);
$asJson = isset($opts['json']);
$mode   = $opts['mode'] ?? null;

if ($mode === null) {
    runOrchestrator();
    exit(0);
}

if (!in_array($mode, ['runtime-only', 'unconditional', 'profile-guided'], true)) {
    fwrite(STDERR, "unknown mode '$mode'\n");
    exit(2);
}

runMode($mode, $asJson);

// =============================================================
// orchestrator (no --mode): runs each mode in a subprocess
// =============================================================
function runOrchestrator(): void
{
    $modes   = ['runtime-only', 'unconditional', 'profile-guided'];
    $results = [];
    foreach ($modes as $mode) {
        $cmd = escapeshellcmd(PHP_BINARY)
             . ' ' . escapeshellarg(__FILE__)
             . ' --mode=' . escapeshellarg($mode)
             . ' --json';
        $out = [];
        $rc  = 0;
        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, "subprocess for mode=$mode failed (rc=$rc):\n" . implode("\n", $out) . "\n");
            exit(1);
        }
        $line = end($out) ?: '';
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "subprocess for mode=$mode emitted unparseable JSON:\n$line\n");
            exit(1);
        }
        $results[$mode] = $decoded;
    }
    printTable($results);
}

/**
 * @param array<string, array<string, mixed>> $results
 */
function printTable(array $results): void
{
    $baseline = $results['runtime-only'] ?? null;
    $unconditional = $results['unconditional'] ?? null;
    $guided   = $results['profile-guided'] ?? null;

    $rows = [];
    $rows[] = ['mode', 'warm_ms', 'peak_mem_kb', 'cache_files', 'hot_ops_s', 'cold_ops_s'];
    foreach (['runtime-only', 'unconditional', 'profile-guided'] as $m) {
        $r = $results[$m];
        $rows[] = [
            $m,
            number_format($r['boot']['warm_ms'], 2),
            number_format($r['boot']['peak_mem_bytes'] / 1024, 0),
            (string) $r['boot']['cache_files'],
            number_format($r['hot']['ops_s'], 0),
            number_format($r['cold']['ops_s'], 0),
        ];
    }

    // Pad columns.
    $widths = array_fill(0, count($rows[0]), 0);
    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i], strlen($cell));
        }
    }
    foreach ($rows as $idx => $row) {
        $line = '';
        foreach ($row as $i => $cell) {
            $line .= str_pad($cell, $widths[$i]) . '  ';
        }
        echo rtrim($line) . "\n";
        if ($idx === 0) {
            $sep = '';
            foreach ($widths as $w) {
                $sep .= str_repeat('-', $w) . '  ';
            }
            echo rtrim($sep) . "\n";
        }
    }

    // Headline deltas.
    if ($baseline && $unconditional && $guided) {
        $memUncond = $unconditional['boot']['peak_mem_bytes'] - $baseline['boot']['peak_mem_bytes'];
        $memGuided = $guided['boot']['peak_mem_bytes']        - $baseline['boot']['peak_mem_bytes'];
        $memSavedPct = $memUncond > 0
            ? (($memUncond - $memGuided) / $memUncond) * 100.0
            : 0.0;
        $hotSpeedupGuided = $guided['hot']['ops_s'] / max(1, $baseline['hot']['ops_s']);
        $hotSpeedupUncond = $unconditional['hot']['ops_s'] / max(1, $baseline['hot']['ops_s']);

        echo "\n";
        echo "memory delta (over runtime-only baseline):\n";
        echo "  unconditional:     +" . number_format($memUncond / 1024, 1) . " KB\n";
        echo "  profile-guided:    +" . number_format($memGuided / 1024, 1) . " KB\n";
        echo "  saving:            " . number_format($memSavedPct, 1) . "%  (target from horizons.md: >30%)\n";
        echo "\n";
        echo "hot DTO speedup vs runtime-only:\n";
        echo "  unconditional:     " . number_format($hotSpeedupUncond, 2) . "×\n";
        echo "  profile-guided:    " . number_format($hotSpeedupGuided, 2) . "×\n";
    }
}

// =============================================================
// per-mode: generate 100 DTOs, warm, measure
// =============================================================
function runMode(string $mode, bool $asJson): void
{
    $tmpDir = sys_get_temp_dir() . '/rxn-pgc-' . bin2hex(random_bytes(4));
    @mkdir($tmpDir, 0775, true);

    try {
        // 1. Generate 100 fixture DTO classes via eval — they're
        //    realistic enough (5 fields, mixed validation attrs)
        //    to exercise the same code paths a real DTO would,
        //    while letting us scale to 100 without writing 100
        //    files by hand.
        [$hotClasses, $coldClasses] = generateDtoClasses();

        // 2. Bag of cast-ready inputs each DTO accepts.
        $bag = [
            'name'        => 'Widget',
            'price'       => '9',
            'active'      => 'true',
            'description' => 'A nice widget',
            'status'      => 'published',
        ];

        // 3. Reset Binder state so each mode starts clean.
        Binder::clearCache();
        BindProfile::reset();

        // 4. Boot phase: configure DumpCache + warm classes per mode.
        $bootStart = microtime(true);
        if ($mode === 'unconditional') {
            DumpCache::useDir($tmpDir);
            foreach (array_merge($hotClasses, $coldClasses) as $class) {
                Binder::compileFor($class);
            }
        } elseif ($mode === 'profile-guided') {
            // Synthesize a profile that names exactly the 10 hot
            // classes — this is what BindProfile would persist
            // after a real workload.
            $profilePath = $tmpDir . '/profile.json';
            $profile = [];
            foreach ($hotClasses as $i => $class) {
                $profile[$class] = 1000 - $i; // descending = top-K
            }
            file_put_contents($profilePath, json_encode($profile));
            DumpCache::useDir($tmpDir);
            Binder::warmFromProfile($profilePath, PROFILE_TOP_K);
        }
        // runtime-only: no warm step, no DumpCache.
        $warmMs = (microtime(true) - $bootStart) * 1000.0;
        $peakMem = memory_get_usage(true);
        $cacheFiles = count(glob($tmpDir . '/*.php') ?: []);

        // 5. Workload phase: bench hot vs cold throughput.
        $hotOps  = benchClasses($hotClasses, $bag);
        $coldOps = benchClasses($coldClasses, $bag);

        $result = [
            'mode' => $mode,
            'boot' => [
                'warm_ms'        => $warmMs,
                'peak_mem_bytes' => $peakMem,
                'cache_files'    => $cacheFiles,
            ],
            'hot'  => $hotOps,
            'cold' => $coldOps,
        ];

        if ($asJson) {
            echo json_encode($result) . "\n";
        } else {
            print_r($result);
        }
    } finally {
        // Cleanup.
        foreach (glob($tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);
    }
}

/**
 * @return array{list<class-string>, list<class-string>} [hot, cold]
 */
function generateDtoClasses(): array
{
    $hot  = [];
    $cold = [];
    for ($i = 0; $i < TOTAL_DTO_COUNT; $i++) {
        $name = "BenchDto_$i";
        $fqcn = "Rxn\\Bench\\ProfileGuided\\Generated\\$name";
        // Vary the class slightly so caching isn't trivially perfect
        // (different sha1 of compiled source → different DumpCache
        // file). The generator emits the same shape; only the docblock
        // tag differs.
        $source = <<<PHP
namespace Rxn\\Bench\\ProfileGuided\\Generated;

use Rxn\\Framework\\Http\\Attribute\\InSet;
use Rxn\\Framework\\Http\\Attribute\\Length;
use Rxn\\Framework\\Http\\Attribute\\Min;
use Rxn\\Framework\\Http\\Attribute\\Required;
use Rxn\\Framework\\Http\\Binding\\RequestDto;

/** @bench-id $i */
class $name implements RequestDto {
    #[Required]
    #[Length(min: 1, max: 100)]
    public string \$name;

    #[Required]
    #[Min(0)]
    public int \$price;

    public bool \$active = true;

    #[Length(max: 500)]
    public ?string \$description = null;

    #[InSet(['draft', 'published', 'archived'])]
    public string \$status = 'draft';
}
PHP;
        eval($source);
        if ($i < HOT_COUNT) {
            $hot[] = $fqcn;
        } else {
            $cold[] = $fqcn;
        }
    }
    return [$hot, $cold];
}

/**
 * @param list<class-string> $classes
 * @param array<string, mixed> $bag
 * @return array{iter: int, ops_s: float, ns_op: float}
 */
function benchClasses(array $classes, array $bag): array
{
    $count = count($classes);
    if ($count === 0) {
        return ['iter' => 0, 'ops_s' => 0.0, 'ns_op' => 0.0];
    }
    // warmup
    $warmEnd = microtime(true) + WARMUP_S;
    $i = 0;
    while (microtime(true) < $warmEnd) {
        Binder::bind($classes[$i++ % $count], $bag);
    }

    $iter  = 0;
    $start = microtime(true);
    $deadline = $start + RUN_S;
    while (microtime(true) < $deadline) {
        Binder::bind($classes[$iter % $count], $bag);
        $iter++;
    }
    $elapsed = microtime(true) - $start;
    return [
        'iter'   => $iter,
        'ops_s'  => $iter / $elapsed,
        'ns_op'  => ($elapsed * 1e9) / max(1, $iter),
    ];
}

/** @param list<string> $argv */
function parseFlags(array $argv): array
{
    $out = [];
    foreach ($argv as $a) {
        if (!str_starts_with($a, '--')) {
            continue;
        }
        $a = substr($a, 2);
        if (str_contains($a, '=')) {
            [$k, $v]  = explode('=', $a, 2);
            $out[$k] = $v;
        } else {
            $out[$a] = true;
        }
    }
    return $out;
}
