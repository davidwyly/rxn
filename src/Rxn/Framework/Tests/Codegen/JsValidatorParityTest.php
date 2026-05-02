<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\JsValidatorEmitter;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\ValidationException;

/**
 * Cross-language validator parity test. Generates a JS module
 * from the same DTO Binder::bind validates server-side, runs N
 * random inputs through both, and asserts the *set of failing
 * fields* matches per input.
 *
 * Skipped when `node` isn't on PATH — the test is meaningful only
 * with both runtimes available. CI image must install Node ≥ 18.
 *
 * Doesn't compare error message text because messages are allowed
 * to drift slightly (e.g. localisation, future i18n). What's
 * load-bearing is "PHP and JS agree on which fields failed."
 */
final class JsValidatorParityTest extends TestCase
{
    private const ITERATIONS = 10_000;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!self::nodeAvailable()) {
            $this->markTestSkipped('node is not on PATH; cross-language parity is not testable in this environment');
        }
        $this->tmpDir = sys_get_temp_dir() . '/rxn-jsparity-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0770, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testParityDtoAgreesWithPhpOnRandomInputs(): void
    {
        $this->runParitySpike(Fixture\ParityDto::class);
    }

    public function testEmitterRefusesUnsupportedAttribute(): void
    {
        $emitter = new JsValidatorEmitter();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Pattern.*no JS twin/i');
        $emitter->emit(Fixture\UnsupportedDto::class);
    }

    private function runParitySpike(string $dtoClass): void
    {
        $emitter = new JsValidatorEmitter();
        $jsCode  = $emitter->emit($dtoClass);
        $jsPath  = $this->tmpDir . '/validator.mjs';
        file_put_contents($jsPath, $jsCode);

        $inputs = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $inputs[] = $this->randomInput($dtoClass);
        }

        $phpFields = array_map(fn (array $bag) => $this->phpFailedFields($dtoClass, $bag), $inputs);
        $jsFields  = $this->jsFailedFieldsBatch($jsPath, $inputs);

        $this->assertCount(self::ITERATIONS, $jsFields);
        $disagreements = 0;
        $samples       = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            if ($phpFields[$i] !== $jsFields[$i]) {
                $disagreements++;
                if (count($samples) < 5) {
                    $samples[] = [
                        'input' => $inputs[$i],
                        'php'   => $phpFields[$i],
                        'js'    => $jsFields[$i],
                    ];
                }
            }
        }

        $this->assertSame(
            0,
            $disagreements,
            "PHP and JS validators disagreed on $disagreements / " . self::ITERATIONS . " inputs.\n"
            . "Samples:\n" . json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return list<string> sorted, unique
     */
    private function phpFailedFields(string $dtoClass, array $bag): array
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

    /**
     * Run the JS validator over `$inputs` in a single node process
     * (one startup amortised across all inputs). Returns one
     * sorted-unique field list per input, in the same order.
     *
     * @param list<array> $inputs
     * @return list<list<string>>
     */
    private function jsFailedFieldsBatch(string $jsPath, array $inputs): array
    {
        $inputsPath  = $this->tmpDir . '/inputs.ndjson';
        $resultsPath = $this->tmpDir . '/results.ndjson';
        $harnessPath = $this->tmpDir . '/harness.mjs';

        $fh = fopen($inputsPath, 'w');
        foreach ($inputs as $bag) {
            fwrite($fh, json_encode($bag, JSON_UNESCAPED_SLASHES) . "\n");
        }
        fclose($fh);

        // Vanilla node harness: import the generated validator,
        // process inputs line-by-line, write back NDJSON of failed
        // fields. No dependencies; pure ES modules.
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
            $jsPath,
            $inputsPath,
            $resultsPath,
        );
        file_put_contents($harnessPath, $harness);

        $cmd = 'node ' . escapeshellarg($harnessPath) . ' 2>&1';
        $output = [];
        $rc = 0;
        exec($cmd, $output, $rc);
        if ($rc !== 0) {
            $this->fail("JS harness failed (rc=$rc):\n" . implode("\n", $output));
        }

        $raw = file_get_contents($resultsPath);
        if ($raw === false) {
            $this->fail("JS harness produced no output file");
        }
        $rows = array_filter(explode("\n", $raw), fn ($l) => $l !== '');
        return array_values(array_map(fn ($l) => json_decode($l, true), $rows));
    }

    /**
     * Random-input generator that's intentionally adversarial about
     * field presence, types, and constraint boundaries. Emits a
     * mix of valid + invalid inputs across the (type × constraint)
     * matrix. Same generator drives both validators — the test
     * asserts agreement, not "JS catches everything PHP catches"
     * (which would be a different claim).
     */
    private function randomInput(string $dtoClass): array
    {
        // The two test fixtures (Tests\Http\Binding\Fixture\CreateProduct
        // and Tests\Codegen\Fixture\ParityDto) cover:
        //   string  : name (required, length 1..100)
        //   int     : price (required, min 0)
        //   slug    : a string with #[Pattern]   <-- NOT in scope, ParityDto avoids
        //   enum    : status (InSet)
        //   bool    : featured (default)
        //   url     : homepage (?string, Url)
        //   email   : email (?string, Email)
        //
        // We generate one bag with the union of fields; properties
        // unknown to a given DTO are ignored by Binder.
        $bag = [];
        if (mt_rand(0, 100) < 80) {
            $bag['name'] = $this->randomStringy();
        }
        if (mt_rand(0, 100) < 80) {
            $bag['price'] = $this->randomNumeric();
        }
        if (mt_rand(0, 100) < 60) {
            $bag['status'] = $this->oneOf(['draft', 'published', 'archived', 'BAD', '', 'Draft']);
        }
        if (mt_rand(0, 100) < 40) {
            $bag['homepage'] = $this->randomUrlish();
        }
        if (mt_rand(0, 100) < 40) {
            $bag['email'] = $this->randomEmailish();
        }
        if (mt_rand(0, 100) < 20) {
            $bag['featured'] = $this->randomBoolish();
        }
        return $bag;
    }

    private function randomStringy(): mixed
    {
        return $this->oneOf([
            '',                                              // empty
            ' ',                                             // blank
            'A',                                             // length 1
            str_repeat('x', 50),                             // mid
            str_repeat('y', 100),                            // exactly max
            str_repeat('z', 101),                            // over
            'normal product name',
            123,                                             // numeric coerce
            true,                                            // bool coerce
            null,                                            // explicit null
            ['nested' => 'object'],                          // array — type mismatch
        ]);
    }

    private function randomNumeric(): mixed
    {
        return $this->oneOf([
            0, 1, -1, 9999, -50,
            '0', '1', '-1', '99', '-50',
            0.0, 1.5, -1.5, 99.99,
            '1.5', '-50.25',
            'abc',                                           // not numeric
            '',                                              // empty
            null,
            true,
            ['nested'],                                      // array
        ]);
    }

    private function randomUrlish(): mixed
    {
        return $this->oneOf([
            'https://example.com',
            'http://example.com/path',
            'https://sub.example.com/path?q=1',
            'ftp://example.com',
            'example.com',                                   // no scheme
            'not-a-url',
            '',
            null,
            123,
        ]);
    }

    private function randomEmailish(): mixed
    {
        return $this->oneOf([
            'a@b.co',
            'user@example.com',
            'user+tag@example.com',
            'not-an-email',
            'a@b',
            '@example.com',
            '',
            null,
        ]);
    }

    private function randomBoolish(): mixed
    {
        return $this->oneOf([
            true, false, 'true', 'false', '1', '0', 'yes', 'no', 'on', 'off',
            'maybe',                                         // invalid
            null, '', 0, 1,
        ]);
    }

    private function oneOf(array $values): mixed
    {
        return $values[array_rand($values)];
    }

    private static function nodeAvailable(): bool
    {
        $rc = 0;
        exec('node --version 2>/dev/null', $_, $rc);
        return $rc === 0;
    }
}
