<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\JsValidatorEmitter;
use Rxn\Framework\Codegen\Testing\ParityHarness;

/**
 * Targeted edge-case parity. The data provider feeds known-
 * tricky single inputs through both validators and asserts they
 * agree. Random testing finds *most* divergences; these tests
 * pin specific corners that the random generator rarely hits.
 *
 * Each row in the provider is a hand-picked input that probes a
 * specific PHP-vs-JS coercion or comparison nuance. If any of
 * these flips, we get a visible failure with diagnostic context
 * (which attribute and which input), instead of a numeric
 * disagreement count.
 */
final class JsValidatorEdgeCaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ParityHarness::nodeAvailable()) {
            $this->markTestSkipped('node not on PATH');
        }
    }

    /**
     * @return iterable<string, array{class-string, array<string, mixed>}>
     */
    public static function knownTrickyInputs(): iterable
    {
        // -- numeric coercion ---------------------------------------------------
        yield 'int: round-trip integer string accepted' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '42', 'strictFloat' => '3.14'],
        ];
        yield 'int: non-roundtrip "42abc" rejected by PHP guard' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '42abc', 'strictFloat' => '3.14'],
        ];
        yield 'int: float-shaped "1.5" rejected (round-trip fails)' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '1.5', 'strictFloat' => '3.14'],
        ];
        yield 'int: leading whitespace "  42" — both reject' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '  42', 'strictFloat' => '3.14'],
        ];
        yield 'int: leading-plus "+42" — PHP int-cast guard' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '+42', 'strictFloat' => '3.14'],
        ];
        yield 'int: negative-zero "-0"' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '-0', 'strictFloat' => '0.0'],
        ];

        yield 'float: scientific "1e3"' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '1', 'strictFloat' => '1e3'],
        ];
        yield 'float: scientific "1.5E-2"' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '1', 'strictFloat' => '1.5E-2'],
        ];
        yield 'float: leading dot ".5"' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '1', 'strictFloat' => '.5'],
        ];
        yield 'float: malformed "1.2.3"' => [
            Fixture\NumericEdgeDto::class,
            ['strictInt' => '1', 'strictFloat' => '1.2.3'],
        ];

        // -- string coercion + Length boundaries --------------------------------
        yield 'string: bool true coerces to "1"' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => true],
        ];
        yield 'string: bool false coerces to "" (rejected as Required-equiv)' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => false],
        ];
        yield 'string: number coerces to its string repr' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => 123],
        ];
        yield 'string: array rejected as type mismatch' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => ['nested']],
        ];
        yield 'Length: exactly at min boundary' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => 'a'],
        ];
        yield 'Length: exactly at max boundary' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => '0123456789'],
        ];
        yield 'Length: max + 1' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => '0123456789x'],
        ];
        yield 'Length: exact-N field with N+1 chars' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => 'ok', 'exactFive' => 'sixxes'],
        ];
        yield 'Length: exact-N field with N-1 chars' => [
            Fixture\StringEdgeDto::class,
            ['shortName' => 'ok', 'exactFive' => 'four'],
        ];

        // -- bool coercion ------------------------------------------------------
        yield 'bool: "true" lowercase accepted' => [
            Fixture\KitchenSinkDto::class,
            ['title' => 'ok', 'quantity' => 1, 'unitPrice' => 1.0, 'taxable' => 'true'],
        ];
        yield 'bool: "FALSE" uppercase accepted (case-insensitive)' => [
            Fixture\KitchenSinkDto::class,
            ['title' => 'ok', 'quantity' => 1, 'unitPrice' => 1.0, 'taxable' => 'FALSE'],
        ];
        yield 'bool: "yes"/"no"/"on"/"off" accepted' => [
            Fixture\KitchenSinkDto::class,
            ['title' => 'ok', 'quantity' => 1, 'unitPrice' => 1.0, 'taxable' => 'yes'],
        ];
        yield 'bool: "maybe" rejected as type mismatch' => [
            Fixture\KitchenSinkDto::class,
            ['title' => 'ok', 'quantity' => 1, 'unitPrice' => 1.0, 'taxable' => 'maybe'],
        ];

        // -- InSet narrowing ---------------------------------------------------
        yield 'InSet: case-mismatch "Draft" vs "draft" rejected' => [
            Fixture\ParityDto::class,
            ['name' => 'ok', 'price' => 1, 'status' => 'Draft'],
        ];
        yield 'InSet: empty string treated as null/missing (default applied)' => [
            Fixture\ParityDto::class,
            ['name' => 'ok', 'price' => 1, 'status' => ''],
        ];

        // -- Url filter --------------------------------------------------------
        yield 'Url: scheme-less "example.com" rejected' => [
            Fixture\ParityDto::class,
            ['name' => 'ok', 'price' => 1, 'homepage' => 'example.com'],
        ];
        yield 'Url: ftp scheme accepted (filter_var allows it)' => [
            Fixture\ParityDto::class,
            ['name' => 'ok', 'price' => 1, 'homepage' => 'ftp://example.com'],
        ];

        // -- Email filter ------------------------------------------------------
        yield 'Email: missing TLD "a@b" rejected' => [
            Fixture\ParityDto::class,
            ['name' => 'ok', 'price' => 1, 'email' => 'a@b'],
        ];
        yield 'Email: plus-tag "user+tag@x.co" accepted' => [
            Fixture\ParityDto::class,
            ['name' => 'ok', 'price' => 1, 'email' => 'user+tag@x.co'],
        ];

        // -- Required vs default vs nullable -----------------------------------
        yield 'Required absent: error' => [
            Fixture\KitchenSinkDto::class,
            ['quantity' => 1, 'unitPrice' => 1.0],
        ];
        yield 'optional default applied when absent' => [
            Fixture\KitchenSinkDto::class,
            ['title' => 'ok', 'quantity' => 1, 'unitPrice' => 1.0],
        ];
        yield 'nullable null accepted' => [
            Fixture\KitchenSinkDto::class,
            ['title' => 'ok', 'quantity' => 1, 'unitPrice' => 1.0, 'featured' => null],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('knownTrickyInputs')]
    public function testKnownTrickyInputAgrees(string $dtoClass, array $input): void
    {
        $emitter = new JsValidatorEmitter();
        // Single-input parity check — bypass the random generator
        // by feeding the harness via a tiny custom invoke wrapper.
        $tmpDir = sys_get_temp_dir() . '/rxn-edge-' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0770, true);
        $srcPath = $tmpDir . '/v.mjs';
        $inPath  = $tmpDir . '/in.ndjson';
        $outPath = $tmpDir . '/out.ndjson';

        file_put_contents($srcPath, $emitter->emit($dtoClass));
        file_put_contents($inPath, json_encode($input, JSON_UNESCAPED_SLASHES) . "\n");

        try {
            (ParityHarness::nodeInvoker())($srcPath, $inPath, $outPath);
            $jsRow = trim((string) file_get_contents($outPath));
            $jsFields = $jsRow === '' ? [] : json_decode($jsRow, true);

            $phpFields = $this->phpFailedFields($dtoClass, $input);

            $this->assertSame(
                $phpFields,
                $jsFields,
                "PHP and JS disagreed on input " . json_encode($input)
                . "\n  PHP: " . json_encode($phpFields)
                . "\n  JS:  " . json_encode($jsFields),
            );
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * @return list<string> sorted, unique
     */
    private function phpFailedFields(string $dtoClass, array $bag): array
    {
        try {
            \Rxn\Framework\Http\Binding\Binder::bind($dtoClass, $bag);
            return [];
        } catch (\Rxn\Framework\Http\Binding\ValidationException $e) {
            $fields = array_unique(array_column($e->errors(), 'field'));
            sort($fields);
            return array_values($fields);
        }
    }
}
