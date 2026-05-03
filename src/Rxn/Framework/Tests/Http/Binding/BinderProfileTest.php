<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\DumpCache;
use Rxn\Framework\Codegen\Profile\BindProfile;
use Rxn\Framework\Http\Binding\Binder;

/**
 * Integration tests for profile-guided compilation. Three things
 * under test:
 *
 * 1. `Binder::bind()` records every call in `BindProfile`.
 * 2. `Binder::bind()` auto-dispatches to the in-memory compiled
 *    cache when one exists for the class — that's how the
 *    pre-warmed hot path actually delivers a speedup.
 * 3. `Binder::warmFromProfile()` reads a profile file, picks the
 *    top-K classes, and populates the compiled cache + writes
 *    `.php` files via DumpCache.
 */
final class BinderProfileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        BindProfile::reset();
        Binder::clearCache();
        $this->tmpDir = sys_get_temp_dir() . '/rxn-profile-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        BindProfile::reset();
        Binder::clearCache();
        DumpCache::useDir(null);
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testBindRecordsHitInProfile(): void
    {
        Binder::bind(Fixture\CreateProduct::class, [
            'name'  => 'Widget',
            'price' => 9,
        ]);
        Binder::bind(Fixture\CreateProduct::class, [
            'name'  => 'Gadget',
            'price' => 12,
        ]);

        $this->assertSame(
            [Fixture\CreateProduct::class => 2],
            BindProfile::counts(),
        );
    }

    public function testBindAutoDispatchesToCompiledCache(): void
    {
        // Pre-populate the compiled cache via compileFor(). After
        // this, bind() must use the closure rather than walking
        // reflection — otherwise profile-guided compilation buys
        // nothing at runtime.
        Binder::compileFor(Fixture\CreateProduct::class);

        // Same input both ways; result must match.
        $dto = Binder::bind(Fixture\CreateProduct::class, [
            'name'  => 'Widget',
            'price' => 9,
        ]);

        $this->assertSame('Widget', $dto->name);
        $this->assertSame(9, $dto->price);
        // Counter still increments on the compiled path so future
        // profile snapshots see the hit.
        $this->assertSame(1, BindProfile::counts()[Fixture\CreateProduct::class] ?? 0);
    }

    public function testWarmFromProfileCompilesTopK(): void
    {
        DumpCache::useDir($this->tmpDir);

        // Seed a profile file with the fixture class as hottest.
        $profilePath = $this->tmpDir . '/profile.json';
        file_put_contents($profilePath, json_encode([
            Fixture\CreateProduct::class => 100,
            'NonExistent\\Class\\Foo'    => 5_000, // garbage: filtered out by class_exists
        ]));

        $warmed = Binder::warmFromProfile($profilePath, 5);

        // Returned in compile order; only the existing class lands.
        $this->assertContains(Fixture\CreateProduct::class, $warmed);

        // Compiled cache populated AND a .php file in DumpCache.
        $files = glob($this->tmpDir . '/*.php') ?: [];
        $this->assertNotEmpty($files, 'warmFromProfile must write at least one compiled .php file');

        // After warming, bind() goes through the compiled fast path.
        $dto = Binder::bind(Fixture\CreateProduct::class, [
            'name'  => 'Warmed',
            'price' => 1,
        ]);
        $this->assertSame('Warmed', $dto->name);
    }

    public function testWarmFromProfileSkipsNonExistentClasses(): void
    {
        $profilePath = $this->tmpDir . '/profile.json';
        file_put_contents($profilePath, json_encode([
            'Some\\Removed\\DtoThatDoesNotExist' => 100,
        ]));

        // Must not throw — non-existent classes (e.g. removed in
        // a refactor since the profile was captured) are silently
        // skipped. Otherwise a stale profile file would block the
        // CLI on every run after a class rename.
        $warmed = Binder::warmFromProfile($profilePath, 5);
        $this->assertSame([], $warmed); // class_exists filtered them
    }

    public function testWarmFromProfileSkipsClassesNotImplementingRequestDto(): void
    {
        // A class that exists at the profiled FQCN but no longer
        // implements `RequestDto` (e.g. someone renamed a regular
        // class onto a previously-DTO name) would make
        // `compileFor()` throw and brick the entire `dump:hot`
        // run. The filter must skip it the same way it skips
        // class_exists failures.
        DumpCache::useDir($this->tmpDir);
        $profilePath = $this->tmpDir . '/profile.json';
        file_put_contents($profilePath, json_encode([
            \stdClass::class                  => 1000, // exists but not a DTO
            Fixture\CreateProduct::class      => 50,   // valid
        ]));

        $warmed = Binder::warmFromProfile($profilePath, 5);
        $this->assertSame([Fixture\CreateProduct::class], $warmed);
    }

    public function testWarmFromProfileResetsCounter(): void
    {
        // After warming, the in-memory counter starts fresh — so
        // the *next* flushTo() reflects only post-warm hits, not
        // the seed values we loaded from disk. Otherwise we'd
        // double-count the seeds at every interval.
        $profilePath = $this->tmpDir . '/profile.json';
        file_put_contents($profilePath, json_encode([
            Fixture\CreateProduct::class => 100,
        ]));

        DumpCache::useDir($this->tmpDir);
        Binder::warmFromProfile($profilePath, 5);

        // No bind() calls yet → no hits.
        $this->assertSame([], BindProfile::counts());

        // One bind() = one hit, not 101.
        Binder::bind(Fixture\CreateProduct::class, [
            'name'  => 'X',
            'price' => 1,
        ]);
        $this->assertSame(1, BindProfile::counts()[Fixture\CreateProduct::class]);
    }
}
