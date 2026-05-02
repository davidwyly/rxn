<?php declare(strict_types=1);

/**
 * A/B microbenchmark driver. Materialise each git ref into its own
 * worktree, install vendor/, run `php bin/bench --json` N times,
 * then compare per-case ops/sec across runs.
 *
 * Usage:
 *   php bench/ab.php --a=main --b=feat/fast-router
 *   php bench/ab.php --a=HEAD~1 --b=HEAD --runs=7 --filter=router
 *   php bench/ab.php --a=main --b=HEAD --keep   # keep worktrees for debugging
 *
 * Defaults: runs=5, no filter, worktrees deleted on exit. Reports
 * per-case median ops/sec for A and B, percent delta, the [min,max]
 * range of each, and a verdict (win / regression / noise).
 *
 * Stats discipline:
 *  - Median of N runs, not mean — robust against the occasional GC
 *    spike. With N=5 the median is the 3rd sample.
 *  - Verdict requires both a >5%% delta AND non-overlapping
 *    [min,max] ranges. The 5%% floor matches `bin/bench`'s
 *    documented per-run variance; range overlap is a cheap
 *    non-parametric stand-in for a t-test at small N.
 *  - We do NOT report mean / stddev. With 5 samples those are not
 *    informative; reporting them invites bad inferences.
 *
 * Caveats:
 *  - The harness runs A then B sequentially on the same machine.
 *    Thermal throttling between runs can bias toward whichever ran
 *    first. For numbers you intend to publish, alternate the order
 *    by hand and verify the verdict is the same.
 *  - Each worktree gets its own `composer install` (no shared
 *    vendor/). First install for an unfamiliar ref is slow but
 *    composer's HTTP / archive cache is shared, so subsequent
 *    installs are seconds.
 */

namespace Rxn\Bench\Ab;

const DEFAULT_RUNS = 5;
const VARIANCE_FLOOR_PCT = 5.0;

$opts = parse_args($argv);
$a       = $opts['a'] ?? null;
$b       = $opts['b'] ?? null;
$runs    = max(1, (int) ($opts['runs'] ?? DEFAULT_RUNS));
$filter  = $opts['filter'] ?? null;
$keep    = isset($opts['keep']);

if ($a === null || $b === null) {
    fwrite(STDERR, "usage: bench/ab.php --a=<ref> --b=<ref> [--runs=N] [--filter=substring] [--keep]\n");
    exit(2);
}

$repo = repo_root();
if ($repo === null) {
    fwrite(STDERR, "ab: not inside a git repo\n");
    exit(2);
}

// Resolve refs to commit shas up front. If a ref doesn't exist we'd
// rather find out before spending 30s on a composer install.
$shaA = resolve_ref($repo, $a);
$shaB = resolve_ref($repo, $b);
if ($shaA === null || $shaB === null) {
    fwrite(STDERR, sprintf("ab: cannot resolve %s\n", $shaA === null ? $a : $b));
    exit(2);
}

fwrite(STDERR, sprintf(
    "ab: A=%s (%s), B=%s (%s), runs=%d%s\n",
    $a, substr($shaA, 0, 12),
    $b, substr($shaB, 0, 12),
    $runs,
    $filter !== null ? ", filter=$filter" : ''
));

$worktrees = [];
$cleanup = static function () use (&$worktrees, $keep, $repo): void {
    foreach ($worktrees as $wt) {
        if ($keep) {
            fwrite(STDERR, "ab: keeping worktree at $wt\n");
            continue;
        }
        run_silent($repo, ['git', 'worktree', 'remove', '--force', $wt]);
    }
};
register_shutdown_function($cleanup);

/** @var array<'a'|'b', array<string, list<float>>> */
$samples = ['a' => [], 'b' => []];

foreach ([['a', $shaA, $a], ['b', $shaB, $b]] as [$slot, $sha, $label]) {
    $wt = make_worktree($repo, $sha);
    $worktrees[] = $wt;
    install_deps($wt, $label);

    for ($i = 1; $i <= $runs; $i++) {
        fwrite(STDERR, sprintf("ab: %s run %d/%d... ", $slot, $i, $runs));
        $rows = run_bench($wt, $filter);
        foreach ($rows as $row) {
            $samples[$slot][$row['case']][] = (float) $row['ops_s'];
        }
        fwrite(STDERR, sprintf("%d cases\n", count($rows)));
    }
}

fwrite(STDOUT, "\n" . render_report($a, $b, $shaA, $shaB, $samples, $runs) . "\n");

// ---------------- helpers ----------------

function parse_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) continue;
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = true;
        }
    }
    return $out;
}

function repo_root(): ?string
{
    $r = run_capture(__DIR__, ['git', 'rev-parse', '--show-toplevel']);
    if ($r['rc'] !== 0) return null;
    return rtrim($r['out']);
}

function resolve_ref(string $repo, string $ref): ?string
{
    $r = run_capture($repo, ['git', 'rev-parse', '--verify', $ref . '^{commit}']);
    if ($r['rc'] !== 0) return null;
    return rtrim($r['out']);
}

function make_worktree(string $repo, string $sha): string
{
    $tmp = rtrim(sys_get_temp_dir(), '/');
    $prefix = $tmp . '/rxn-ab-' . substr($sha, 0, 8) . '-';

    $dir = null;
    for ($i = 0; $i < 16; $i++) {
        $candidate = $prefix . bin2hex(random_bytes(16));
        if (@mkdir($candidate, 0700)) {
            $dir = $candidate;
            break;
        }
    }
    if ($dir === null) {
        fwrite(STDERR, "ab: unable to create private temp worktree dir\n");
        exit(1);
    }

    // Worktrees can't be reused across refs; force-add a detached
    // checkout at this sha, isolated from any branch.
    $r = run_capture($repo, ['git', 'worktree', 'add', '--detach', $dir, $sha]);
    if ($r['rc'] !== 0) {
        fwrite(STDERR, "ab: git worktree add failed: " . $r['err'] . "\n");
        exit(1);
    }
    return $dir;
}

function install_deps(string $wt, string $label): void
{
    if (is_dir($wt . '/vendor')) {
        return;
    }
    fwrite(STDERR, "ab: installing $label deps in worktree...\n");
    putenv('COMPOSER_ALLOW_SUPERUSER=1');
    $r = run_capture($wt, ['composer', 'install', '--no-interaction', '--prefer-dist', '--quiet']);
    if ($r['rc'] !== 0) {
        fwrite(STDERR, "ab: composer install failed:\n" . $r['err'] . "\n");
        exit(1);
    }
}

/**
 * @return list<array{case: string, ops_s: float, ns_op: float, iter: int}>
 */
function run_bench(string $wt, ?string $filter): array
{
    $cmd = [PHP_BINARY, $wt . '/bin/bench', '--json'];
    if ($filter !== null) $cmd[] = $filter;
    $r = run_capture($wt, $cmd);
    if ($r['rc'] !== 0) {
        fwrite(STDERR, "ab: bin/bench failed:\n" . $r['err'] . "\n");
        return [];
    }
    $rows = json_decode($r['out'], true);
    if (!is_array($rows)) {
        fwrite(STDERR, "ab: bin/bench produced non-JSON output\n");
        return [];
    }
    return $rows;
}

/**
 * @return array{rc: int, out: string, err: string}
 */
function run_capture(string $cwd, array $cmd): array
{
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        return ['rc' => -1, 'out' => '', 'err' => 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc  = proc_close($proc);
    return ['rc' => $rc, 'out' => (string) $out, 'err' => (string) $err];
}

function run_silent(string $cwd, array $cmd): void
{
    run_capture($cwd, $cmd);
}

/**
 * @param array<string, list<float>> $cases  case → list of ops/sec samples
 * @return array{med: float, min: float, max: float}
 */
function summarise(array $samples): array
{
    if ($samples === []) return ['med' => 0.0, 'min' => 0.0, 'max' => 0.0];
    sort($samples);
    $n = count($samples);
    $med = $n % 2 ? $samples[intdiv($n, 2)]
                  : ($samples[$n / 2 - 1] + $samples[$n / 2]) / 2.0;
    return ['med' => $med, 'min' => $samples[0], 'max' => $samples[$n - 1]];
}

function verdict(array $aSummary, array $bSummary): array
{
    $aMed = $aSummary['med'];
    $bMed = $bSummary['med'];
    if ($aMed <= 0.0) {
        return ['label' => 'noise', 'delta_pct' => 0.0];
    }
    $delta = ($bMed - $aMed) / $aMed * 100.0;
    if (abs($delta) < VARIANCE_FLOOR_PCT) {
        return ['label' => 'noise', 'delta_pct' => $delta];
    }
    // Non-parametric range-disjoint check. With N=5 this approximates
    // a Mann-Whitney U at p~0.04 — adequate for a homebrew tool.
    if ($delta > 0 && $aSummary['max'] < $bSummary['min']) {
        return ['label' => 'win', 'delta_pct' => $delta];
    }
    if ($delta < 0 && $bSummary['max'] < $aSummary['min']) {
        return ['label' => 'regression', 'delta_pct' => $delta];
    }
    return ['label' => 'uncertain', 'delta_pct' => $delta];
}

function render_report(string $a, string $b, string $shaA, string $shaB, array $samples, int $runs): string
{
    $cases = array_unique(array_merge(
        array_keys($samples['a']),
        array_keys($samples['b'])
    ));
    sort($cases);

    $lines  = [];
    $lines[] = '# A/B microbenchmark report';
    $lines[] = '';
    $lines[] = sprintf(
        '_A = `%s` (%s) — B = `%s` (%s) — runs = %d_',
        $a, substr($shaA, 0, 12), $b, substr($shaB, 0, 12), $runs
    );
    $lines[] = '';
    $lines[] = '| case | A median ops/s | B median ops/s | Δ % | A range | B range | verdict |';
    $lines[] = '|---|---:|---:|---:|---:|---:|---|';

    foreach ($cases as $case) {
        $a_s = summarise($samples['a'][$case] ?? []);
        $b_s = summarise($samples['b'][$case] ?? []);
        $v   = verdict($a_s, $b_s);

        $lines[] = sprintf(
            '| `%s` | %s | %s | %+.1f%% | %s..%s | %s..%s | **%s** |',
            $case,
            number_format($a_s['med'], 0),
            number_format($b_s['med'], 0),
            $v['delta_pct'],
            number_format($a_s['min'], 0),
            number_format($a_s['max'], 0),
            number_format($b_s['min'], 0),
            number_format($b_s['max'], 0),
            $v['label']
        );
    }

    $lines[] = '';
    $lines[] = sprintf(
        '_Generated %s. Verdict requires |Δ| > %.0f%% AND non-overlapping [min, max] ranges._',
        date('c'),
        VARIANCE_FLOOR_PCT
    );
    return implode("\n", $lines);
}
