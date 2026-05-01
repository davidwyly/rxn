<?php declare(strict_types=1);

/**
 * Driver for the cross-framework comparison benchmark. For each
 * selected framework: install (if needed), boot `php -S` against
 * its public/, warm up, run the load generator across the shared
 * route table, kill the server, repeat. Emit a Markdown table and
 * (optionally) write it to results/.
 *
 * Usage:
 *   php bench/compare/run.php
 *   php bench/compare/run.php --frameworks=rxn,raw --duration=3
 *   php bench/compare/run.php --concurrency=50 --duration=10
 *   php bench/compare/run.php --skip-install
 *
 * Defaults: all four apps, concurrency=20, duration=5s, warmup=1s.
 *
 * Caveats this harness does NOT pretend to address:
 *  - `php -S` is a development server. PHP-FPM behind nginx will
 *    produce different absolute numbers (usually higher RPS, lower
 *    p99). Comparisons across frameworks on the *same* harness are
 *    meaningful; comparisons against published numbers from someone
 *    else's `wrk + nginx + FPM` rig are not.
 *  - The load generator runs in the same process the user sees the
 *    output from, so it competes with the framework for cycles.
 *    Increasing concurrency past ~50 starts measuring the
 *    generator's overhead instead of the server's.
 *  - We exercise dotted JSON shapes only — no streaming, no large
 *    bodies, no binary content.
 */

namespace Rxn\Bench\Compare;

require __DIR__ . '/load.php';

const FRAMEWORKS = [
    'rxn'     => [
        'public'    => __DIR__ . '/apps/rxn/public',
        'composer'  => null,                          // uses repo-root vendor
        'workers'   => 4,
        'description' => 'Rxn (Router + Binder)',
    ],
    'slim'    => [
        'public'    => __DIR__ . '/apps/slim/public',
        'composer'  => __DIR__ . '/apps/slim',
        'workers'   => 4,
        'description' => 'Slim 4 + slim/psr7',
    ],
    'symfony' => [
        'public'    => __DIR__ . '/apps/symfony/public',
        'composer'  => __DIR__ . '/apps/symfony',
        'workers'   => 4,
        'description' => 'Symfony micro-kernel (HttpKernel + Routing)',
    ],
    'raw'     => [
        'public'    => __DIR__ . '/apps/raw/public',
        'composer'  => null,
        'workers'   => 4,
        'description' => 'Raw PHP (no framework, no PSR-7)',
    ],
];

const ROUTES = [
    [
        'name'    => 'GET /hello',
        'method'  => 'GET',
        'path'    => '/hello',
        'body'    => null,
        'headers' => [],
        'expect_status' => 200,
    ],
    [
        'name'    => 'GET /products/{id:int}',
        'method'  => 'GET',
        'path'    => '/products/42',
        'body'    => null,
        'headers' => [],
        'expect_status' => 200,
    ],
    [
        'name'    => 'POST /products (valid)',
        'method'  => 'POST',
        'path'    => '/products',
        'body'    => '{"name":"Widget","price":9.99}',
        'headers' => ['Content-Type: application/json'],
        'expect_status' => 201,
    ],
    [
        'name'    => 'POST /products (422)',
        'method'  => 'POST',
        'path'    => '/products',
        'body'    => '{"name":"","price":-1}',
        'headers' => ['Content-Type: application/json'],
        'expect_status' => 422,
    ],
];

$args = parse_args($argv);
$opts = [
    'frameworks'  => isset($args['frameworks'])
        ? array_map('trim', explode(',', $args['frameworks']))
        : array_keys(FRAMEWORKS),
    'duration'    => (float) ($args['duration']    ?? 5.0),
    'concurrency' => (int)   ($args['concurrency'] ?? 20),
    'warmup'      => (float) ($args['warmup']      ?? 1.0),
    'skip_install' => isset($args['skip-install']),
    'host'        => $args['host'] ?? '127.0.0.1',
];

fwrite(STDERR, sprintf(
    "compare: %d framework(s), %ss, c=%d, warmup=%ss\n",
    count($opts['frameworks']),
    $opts['duration'],
    $opts['concurrency'],
    $opts['warmup']
));

$results = [];
foreach ($opts['frameworks'] as $name) {
    if (!isset(FRAMEWORKS[$name])) {
        fwrite(STDERR, "compare: skipping unknown framework '$name'\n");
        continue;
    }
    $fw = FRAMEWORKS[$name];

    if ($fw['composer'] !== null && !$opts['skip_install']) {
        if (!ensure_installed($name, $fw['composer'])) {
            fwrite(STDERR, "compare: skipping '$name' — install failed\n");
            continue;
        }
    }
    if ($fw['composer'] !== null && !is_dir($fw['composer'] . '/vendor')) {
        fwrite(STDERR, "compare: skipping '$name' — vendor/ missing (run without --skip-install)\n");
        continue;
    }

    $port = pick_free_port();
    $proc = start_server($opts['host'], $port, $fw['public'], $fw['workers']);
    if ($proc === null) {
        fwrite(STDERR, "compare: failed to spawn server for '$name'\n");
        continue;
    }

    try {
        if (!wait_for_ready($opts['host'], $port, 5.0)) {
            fwrite(STDERR, "compare: '$name' didn't start listening on $port\n");
            continue;
        }

        $results[$name] = [];
        foreach (ROUTES as $route) {
            $req = build_request($opts['host'], $port, $route);

            // Sanity check: does the app actually answer the route
            // with the expected status?
            [$code, $body] = Load::once($req);
            if ($code !== $route['expect_status']) {
                fwrite(STDERR, sprintf(
                    "compare: %s %s returned %d (expected %d): %.200s\n",
                    $name,
                    $route['name'],
                    $code,
                    $route['expect_status'],
                    $body
                ));
                $results[$name][$route['name']] = ['error' => "wrong status $code"];
                continue;
            }

            Load::warmup($req, max(20, (int) ($opts['warmup'] * 200)));

            fwrite(STDERR, sprintf("compare: %-7s | %s ... ", $name, $route['name']));
            $r = Load::run($req, $opts['concurrency'], $opts['duration']);

            // Let the built-in server drain its accept backlog before
            // the next route's sanity check fires; otherwise the
            // first request of the next route can hit a saturated
            // worker pool and time out spuriously.
            usleep(250_000);
            fwrite(STDERR, sprintf(
                "%8s rps (median-window %s) (p50 %5.2f ms / p99 %6.2f ms)\n",
                number_format($r['rps'], 0),
                number_format($r['rps_median_window'], 0),
                $r['p50_ms'],
                $r['p99_ms']
            ));
            $results[$name][$route['name']] = $r;
        }
    } finally {
        stop_server($proc);
    }
}

fwrite(STDOUT, "\n" . render_markdown($results, $opts) . "\n");

$resultsDir = __DIR__ . '/results';
if (!is_dir($resultsDir)) {
    @mkdir($resultsDir, 0775, true);
}
$stamp = date('Y-m-d_His');
$out   = $resultsDir . "/results_$stamp.md";
@file_put_contents($out, render_markdown($results, $opts));
fwrite(STDERR, "compare: wrote $out\n");

// ----- helpers -----

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

function ensure_installed(string $name, string $dir): bool
{
    if (is_dir($dir . '/vendor')) {
        return true;
    }
    fwrite(STDERR, "compare: installing '$name'...\n");
    $cmd = sprintf(
        'cd %s && composer install --no-interaction --no-dev --prefer-dist --quiet 2>&1',
        escapeshellarg($dir)
    );
    putenv('COMPOSER_ALLOW_SUPERUSER=1');
    exec($cmd, $output, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, implode("\n", $output) . "\n");
        return false;
    }
    return is_dir($dir . '/vendor');
}

function pick_free_port(): int
{
    // Bind ephemerally, read back the assigned port, then close so
    // the OS hands the same port to `php -S` a moment later. There's
    // a small race here, but it's the common idiom and good enough
    // for a developer-machine harness.
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_bind($sock, '127.0.0.1', 0);
    socket_getsockname($sock, $_, $port);
    socket_close($sock);
    return $port;
}

/**
 * @return array{proc: resource, log: string}|null
 */
function start_server(string $host, int $port, string $docroot, int $workers)
{
    $env = $_ENV + getenv() + ['PHP_CLI_SERVER_WORKERS' => (string) $workers];
    $logFile = sys_get_temp_dir() . '/rxn-bench-' . bin2hex(random_bytes(4)) . '.log';
    // Send stdout + stderr straight to a file. The built-in server
    // logs every request to stderr; under load the default pipe
    // buffer fills inside one second and the server blocks on
    // write(), tanking throughput. A file descriptor doesn't.
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logFile, 'w'],
        2 => ['file', $logFile, 'w'],
    ];
    $cmd = sprintf(
        '%s -S %s:%d -t %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($host),
        $port,
        escapeshellarg($docroot)
    );
    $proc = proc_open($cmd, $descriptors, $_pipes, null, $env);
    if (!is_resource($proc)) {
        return null;
    }
    return ['proc' => $proc, 'log' => $logFile];
}

function stop_server($state): void
{
    if (!is_array($state)) return;
    if (is_resource($state['proc'] ?? null)) {
        proc_terminate($state['proc'], SIGTERM);
        $deadline = microtime(true) + 1.5;
        while (microtime(true) < $deadline) {
            $info = proc_get_status($state['proc']);
            if (!$info['running']) break;
            usleep(50_000);
        }
        $info = proc_get_status($state['proc']);
        if ($info['running']) {
            proc_terminate($state['proc'], SIGKILL);
        }
        proc_close($state['proc']);
    }
    if (!empty($state['log']) && file_exists($state['log'])) {
        @unlink($state['log']);
    }
}

function wait_for_ready(string $host, int $port, float $timeout): bool
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.1);
        if ($fp) {
            fclose($fp);
            return true;
        }
        usleep(50_000);
    }
    return false;
}

function build_request(string $host, int $port, array $route): array
{
    return [
        'url'     => sprintf('http://%s:%d%s', $host, $port, $route['path']),
        'method'  => $route['method'],
        'body'    => $route['body'],
        'headers' => $route['headers'],
    ];
}

function render_markdown(array $results, array $opts): string
{
    if ($results === []) {
        return "_(no results)_\n";
    }

    $routeNames = [];
    foreach (ROUTES as $r) {
        $routeNames[] = $r['name'];
    }

    $lines  = [];
    $lines[] = '# Cross-framework comparison';
    $lines[] = '';
    $lines[] = sprintf(
        '_concurrency=%d, duration=%ss, warmup=%ss, runner=`php -S` on %s_',
        $opts['concurrency'],
        $opts['duration'],
        $opts['warmup'],
        $opts['host']
    );
    $lines[] = '';
    $lines[] = '## Throughput — count / duration (req/s — higher is better)';
    $lines[] = '';
    $lines[] = '| Framework | ' . implode(' | ', $routeNames) . ' |';
    $lines[] = '|---|' . str_repeat('---:|', count($routeNames));
    foreach ($results as $fw => $perRoute) {
        $cells = [];
        foreach ($routeNames as $rn) {
            $r = $perRoute[$rn] ?? null;
            if (!is_array($r) || isset($r['error'])) {
                $cells[] = $r['error'] ?? '—';
            } else {
                $cells[] = number_format($r['rps'], 0);
            }
        }
        $lines[] = '| `' . $fw . '` | ' . implode(' | ', $cells) . ' |';
    }
    $lines[] = '';
    $lines[] = '## Throughput — median 100ms-window (req/s — robust to brief stalls, use this for A/B)';
    $lines[] = '';
    $lines[] = '| Framework | ' . implode(' | ', $routeNames) . ' |';
    $lines[] = '|---|' . str_repeat('---:|', count($routeNames));
    foreach ($results as $fw => $perRoute) {
        $cells = [];
        foreach ($routeNames as $rn) {
            $r = $perRoute[$rn] ?? null;
            if (!is_array($r) || isset($r['error'])) {
                $cells[] = $r['error'] ?? '—';
            } else {
                $cells[] = number_format($r['rps_median_window'], 0);
            }
        }
        $lines[] = '| `' . $fw . '` | ' . implode(' | ', $cells) . ' |';
    }
    $lines[] = '';
    $lines[] = '## Latency p50 / p99 (ms — lower is better)';
    $lines[] = '';
    $lines[] = '| Framework | ' . implode(' | ', $routeNames) . ' |';
    $lines[] = '|---|' . str_repeat('---:|', count($routeNames));
    foreach ($results as $fw => $perRoute) {
        $cells = [];
        foreach ($routeNames as $rn) {
            $r = $perRoute[$rn] ?? null;
            if (!is_array($r) || isset($r['error'])) {
                $cells[] = '—';
            } else {
                $cells[] = sprintf('%.2f / %.2f', $r['p50_ms'], $r['p99_ms']);
            }
        }
        $lines[] = '| `' . $fw . '` | ' . implode(' | ', $cells) . ' |';
    }
    $lines[] = '';
    $lines[] = sprintf(
        '_Generated %s by `bench/compare/run.php`._',
        date('c')
    );
    $lines[] = '';
    return implode("\n", $lines);
}
