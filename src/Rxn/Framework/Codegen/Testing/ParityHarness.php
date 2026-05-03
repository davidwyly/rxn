<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen\Testing;

use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Binding\ValidationException;

/**
 * Generic cross-runtime parity harness for plugins that emit a
 * validator in another language. Drives N adversarial inputs
 * through both the PHP reference (`Binder::bind`) and the
 * plugin's emitted code, asserts agreement on the *set of
 * failing fields* per input, returns the disagreement count.
 *
 * Plugin contract (from `docs/plugin-architecture.md`):
 *
 *   final class ParityTest extends TestCase
 *   {
 *       public function testGeneratorAgreesWithPhp(): void
 *       {
 *           $result = ParityHarness::run(
 *               dto:        ParityDto::class,
 *               source:     (new MyGenerator())->emit(ParityDto::class),
 *               invoke:     fn ($srcPath, $inPath, $outPath) => $this->runMyRuntime(...),
 *               iterations: 10_000,
 *           );
 *           $this->assertSame(0, $result->disagreements, $result->describe());
 *       }
 *   }
 *
 * The `invoke` callable is plugin-specific — it's the bridge
 * between the harness (which knows nothing about node, python,
 * go, etc.) and the target runtime. It must read inputs as
 * NDJSON, run them through the plugin's validator, and write
 * one JSON-encoded list of failing field names per line to the
 * outputs path.
 *
 * The harness handles: PHP-side validation via Binder::bind,
 * input generation via AdversarialInputGenerator, file I/O for
 * inputs/outputs, the field-set comparison, and the
 * disagreement-sample collection for failure reporting.
 *
 * Returns `ParityResult` so callers can decide their own
 * threshold (zero is the standard, but a flaky third-party
 * runtime might have its own tolerance — though that wouldn't
 * be a parity-tested plugin in good standing).
 */
final class ParityHarness
{
    /**
     * @param class-string<RequestDto> $dto
     * @param string $source generated target-language source for the validator
     * @param callable(string $srcPath, string $inputsPath, string $outputsPath): void $invoke
     *   Runs the target runtime against the generated source.
     *   Reads NDJSON from $inputsPath, writes NDJSON-of-string-lists to $outputsPath.
     * @param int $iterations number of random inputs to drive
     * @param string $extension extension to use for the source file (e.g. 'mjs', 'py', 'ts')
     */
    public static function run(
        string $dto,
        string $source,
        callable $invoke,
        int $iterations = 10_000,
        string $extension = 'mjs',
        ?AdversarialInputGenerator $generator = null,
    ): ParityResult {
        $generator ??= new AdversarialInputGenerator();

        $tmpDir = sys_get_temp_dir() . '/rxn-parity-' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0770, true);

        try {
            $srcPath = $tmpDir . '/validator.' . ltrim($extension, '.');
            $inPath  = $tmpDir . '/inputs.ndjson';
            $outPath = $tmpDir . '/outputs.ndjson';
            file_put_contents($srcPath, $source);

            // Generate N inputs, write as NDJSON for the target runtime.
            $inputs = [];
            $fh = fopen($inPath, 'w');
            for ($i = 0; $i < $iterations; $i++) {
                $bag = $generator->generate($dto);
                $inputs[] = $bag;
                fwrite($fh, json_encode($bag, JSON_UNESCAPED_SLASHES) . "\n");
            }
            fclose($fh);

            // PHP side — sequential, one Binder::bind call per input.
            $phpFields = array_map(
                static fn (array $bag) => self::phpFailedFields($dto, $bag),
                $inputs,
            );

            // Target runtime side — single invocation amortises startup
            // across all iterations.
            ($invoke)($srcPath, $inPath, $outPath);

            $raw = @file_get_contents($outPath);
            if ($raw === false) {
                throw new \RuntimeException("ParityHarness: target runtime produced no output at $outPath");
            }
            $rows = array_filter(explode("\n", $raw), static fn ($l) => $l !== '');
            if (count($rows) !== $iterations) {
                throw new \RuntimeException(
                    "ParityHarness: target runtime emitted " . count($rows)
                    . " result lines, expected $iterations",
                );
            }
            $targetFields = array_values(array_map(
                static fn ($l) => json_decode($l, true) ?? [],
                $rows,
            ));

            // Compare per-input.
            $disagreements = 0;
            $samples       = [];
            for ($i = 0; $i < $iterations; $i++) {
                if ($phpFields[$i] !== $targetFields[$i]) {
                    $disagreements++;
                    if (count($samples) < 5) {
                        $samples[] = [
                            'input'  => $inputs[$i],
                            'php'    => $phpFields[$i],
                            'target' => $targetFields[$i],
                        ];
                    }
                }
            }

            return new ParityResult($disagreements, $iterations, $samples);
        } finally {
            // Cleanup unconditionally so test failures don't leave junk.
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Standard NDJSON-driven Node invocation. Most JS-family
     * plugins use this directly. Plugins targeting other
     * runtimes (python, go, ...) provide their own equivalent.
     *
     * @return callable(string, string, string): void
     */
    public static function nodeInvoker(): callable
    {
        return static function (string $srcPath, string $inPath, string $outPath): void {
            $harness = sprintf(
                <<<'JS'
                import { validate } from '%s';
                import { readFileSync, writeFileSync } from 'node:fs';

                const lines = readFileSync('%s', 'utf8').split('\n').filter(Boolean);
                const out = [];
                for (const line of lines) {
                  const input = JSON.parse(line);
                  const result = validate(input);
                  const fields = [...new Set(result.errors.map(e => e.field))].sort();
                  out.push(JSON.stringify(fields));
                }
                writeFileSync('%s', out.join('\n'));
                JS,
                $srcPath,
                $inPath,
                $outPath,
            );
            $harnessPath = dirname($srcPath) . '/harness.mjs';
            file_put_contents($harnessPath, $harness);
            $output = [];
            $rc = 0;
            exec('node ' . escapeshellarg($harnessPath) . ' 2>&1', $output, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException(
                    "ParityHarness: node invocation failed (rc=$rc):\n" . implode("\n", $output),
                );
            }
        };
    }

    public static function nodeAvailable(): bool
    {
        $rc = 0;
        exec('node --version 2>/dev/null', $_, $rc);
        return $rc === 0;
    }

    /**
     * @return list<string> sorted, unique
     */
    private static function phpFailedFields(string $dtoClass, array $bag): array
    {
        try {
            Binder::bind($dtoClass, $bag);
            return [];
        } catch (ValidationException $e) {
            $fields = array_unique(array_column($e->errors(), 'field'));
            sort($fields);
            return array_values($fields);
        }
    }
}
