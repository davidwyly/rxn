<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Profile;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\Profile\BindProfile;

/**
 * Unit tests for the in-memory hit counter + atomic JSON
 * persistence. Each test resets the static slot before running so
 * test ordering doesn't matter.
 */
final class BindProfileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        BindProfile::reset();
        $this->tmpDir = sys_get_temp_dir() . '/rxn-bindprofile-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        BindProfile::reset();
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testRecordIncrementsCounter(): void
    {
        BindProfile::record('App\\Foo');
        BindProfile::record('App\\Foo');
        BindProfile::record('App\\Bar');

        $this->assertSame(['App\\Foo' => 2, 'App\\Bar' => 1], BindProfile::counts());
    }

    public function testTopKReturnsHottestFirst(): void
    {
        BindProfile::record('App\\Cold');
        for ($i = 0; $i < 100; $i++) {
            BindProfile::record('App\\Hot');
        }
        for ($i = 0; $i < 50; $i++) {
            BindProfile::record('App\\Warm');
        }

        $this->assertSame(['App\\Hot', 'App\\Warm', 'App\\Cold'], BindProfile::topK(3));
        $this->assertSame(['App\\Hot', 'App\\Warm'], BindProfile::topK(2));
    }

    public function testTopKBreaksTiesByName(): void
    {
        // Determinism matters — snapshot tests on the dump output
        // need the same input to produce the same selection.
        BindProfile::record('App\\B');
        BindProfile::record('App\\A');
        $this->assertSame(['App\\A', 'App\\B'], BindProfile::topK(2));
    }

    public function testTopKZeroOrNegativeReturnsEmpty(): void
    {
        BindProfile::record('App\\Foo');
        $this->assertSame([], BindProfile::topK(0));
        $this->assertSame([], BindProfile::topK(-5));
    }

    public function testFlushToWritesAtomicallyAndRoundTrips(): void
    {
        BindProfile::record('App\\Foo');
        BindProfile::record('App\\Foo');
        BindProfile::record('App\\Bar');

        $path = $this->tmpDir . '/profile.json';
        BindProfile::flushTo($path);

        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame(['App\\Foo' => 2, 'App\\Bar' => 1], $decoded);
    }

    public function testFlushToMergesWithExistingProfile(): void
    {
        // First worker writes its hits.
        BindProfile::record('App\\Foo');
        BindProfile::record('App\\Foo');
        $path = $this->tmpDir . '/profile.json';
        BindProfile::flushTo($path);

        // Second worker (or next interval): different hits, merge in.
        BindProfile::reset();
        BindProfile::record('App\\Foo');
        BindProfile::record('App\\Bar');
        BindProfile::flushTo($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        // Foo: 2 from first write + 1 from second = 3
        // Bar: 0 from first  + 1 from second = 1
        $this->assertSame(['App\\Foo' => 3, 'App\\Bar' => 1], $decoded);
    }

    public function testLoadFromReadsCounter(): void
    {
        $path = $this->tmpDir . '/profile.json';
        file_put_contents($path, json_encode(['App\\Foo' => 42, 'App\\Bar' => 7]));

        BindProfile::loadFrom($path);
        $this->assertSame(['App\\Foo' => 42, 'App\\Bar' => 7], BindProfile::counts());
    }

    public function testLoadFromReplacesInMemoryCounter(): void
    {
        BindProfile::record('App\\Existing');
        $path = $this->tmpDir . '/profile.json';
        file_put_contents($path, json_encode(['App\\Loaded' => 5]));

        BindProfile::loadFrom($path);
        // The pre-existing in-memory hit is replaced, not merged —
        // matches the "fresh start from disk" semantics that
        // warmFromProfile relies on.
        $this->assertArrayNotHasKey('App\\Existing', BindProfile::counts());
        $this->assertSame(['App\\Loaded' => 5], BindProfile::counts());
    }

    public function testLoadFromMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no profile at/');
        BindProfile::loadFrom($this->tmpDir . '/nonexistent.json');
    }

    public function testLoadFromCorruptedFileTreatsAsEmpty(): void
    {
        // A garbled file (perhaps interrupted write before atomic
        // rename was added — but defensive nonetheless) should not
        // crash the next reader. Drop unparseable content.
        $path = $this->tmpDir . '/profile.json';
        file_put_contents($path, 'not valid json {');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no profile at/');
        BindProfile::loadFrom($path);
    }

    public function testLoadFromDropsEntriesWithBadValues(): void
    {
        $path = $this->tmpDir . '/profile.json';
        // Mixed: one valid entry, one negative count, one non-int,
        // one non-string key. Defensive load drops the bad ones,
        // keeps the good one.
        file_put_contents($path, json_encode([
            'App\\Good' => 10,
            'App\\Bad'  => -5,
            'App\\Str'  => 'oops',
            42          => 100,
        ]));

        BindProfile::loadFrom($path);
        $this->assertSame(['App\\Good' => 10], BindProfile::counts());
    }

    public function testResetEmptiesCounter(): void
    {
        BindProfile::record('App\\Foo');
        BindProfile::reset();
        $this->assertSame([], BindProfile::counts());
    }
}
