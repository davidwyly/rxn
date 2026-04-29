<?php declare(strict_types=1);

/**
 * Rxn opcache preload script.
 *
 * Pre-compiles every framework class into shared opcode cache at
 * php-fpm boot. Subsequent requests skip the parse / compile step
 * entirely — the classes are already in opcache, ready to dispatch.
 *
 * Wire this up in php.ini (or the pool config):
 *
 *   opcache.preload=/var/www/rxn/bin/preload.php
 *   opcache.preload_user=www-data
 *
 * Reload php-fpm after edits to either preload script *or* the
 * preloaded source files; opcache holds the compiled bytecode in
 * shared memory until the master is restarted.
 *
 * The script is intentionally narrow: it preloads the framework
 * tree only. App code, vendor libraries, and test fixtures are
 * left out so a deploy that only changes app/ doesn't require an
 * fpm restart. Apps that want to extend preloading can require
 * this file from their own preload script and add their classes
 * after.
 */

if (!function_exists('opcache_compile_file')) {
    error_log('rxn preload: opcache extension is not available; nothing to do.');
    return;
}

$projectRoot   = dirname(__DIR__);
$frameworkRoot = $projectRoot . '/src/Rxn/Framework';
$autoload      = $projectRoot . '/vendor/autoload.php';

if (!is_file($autoload)) {
    error_log("rxn preload: vendor/autoload.php missing at $autoload — run composer install.");
    return;
}

require_once $autoload;

// Preload the PSR interface tree first. opcache validates the full
// inheritance graph at preload time; without these in place, classes
// like Psr15Pipeline that implement PSR-15 RequestHandlerInterface
// emit "Unknown interface" warnings during boot.
$psrRoots = [
    $projectRoot . '/vendor/psr/http-message/src',
    $projectRoot . '/vendor/psr/http-factory/src',
    $projectRoot . '/vendor/psr/http-server-handler/src',
    $projectRoot . '/vendor/psr/http-server-middleware/src',
];
foreach ($psrRoots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    foreach (glob($root . '/*.php') ?: [] as $f) {
        @opcache_compile_file($f);
    }
}

$compiled = 0;
$skipped  = 0;
$failed   = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($frameworkRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    // Skip the test tree — preloading test cases buys nothing in
    // production and pulls in PHPUnit, which isn't always installed
    // on the production image.
    if (str_contains($path, '/Tests/')) {
        $skipped++;
        continue;
    }
    try {
        if (opcache_compile_file($path)) {
            $compiled++;
        } else {
            $failed[] = $path;
        }
    } catch (\Throwable $e) {
        // Some framework files have top-level side effects or
        // depend on classes not yet loaded; opcache_compile_file
        // can throw on those. Note them but don't abort the boot.
        $failed[] = $path . ' (' . $e->getMessage() . ')';
    }
}

error_log(sprintf(
    'rxn preload: compiled %d files, skipped %d, %d failed.',
    $compiled,
    $skipped,
    count($failed),
));
foreach ($failed as $f) {
    error_log("  ! $f");
}
