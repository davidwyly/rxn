<?php declare(strict_types=1);

namespace Rxn\Framework\Tests;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Container;
use Rxn\Framework\Error\ContainerException;
use Rxn\Framework\Tests\Fixture\Container\Clock;
use Rxn\Framework\Tests\Fixture\Container\FrozenClock;
use Rxn\Framework\Tests\Fixture\Container\SystemClock;
use Rxn\Framework\Tests\Fixture\Container\Timestamper;
use Rxn\Framework\Tests\Fixture\Container\UserRepo;
use Rxn\Framework\Tests\Fixture\Container\MemoryUserRepo;
use Rxn\Framework\Tests\Fixture\Container\NeedsDefaultBag;
use Rxn\Framework\Utility\Logger;

final class ContainerTest extends TestCase
{
    public function testBindsInterfaceToConcrete(): void
    {
        $c = new Container();
        $c->bind(UserRepo::class, MemoryUserRepo::class);

        $repo = $c->get(UserRepo::class);
        $this->assertInstanceOf(MemoryUserRepo::class, $repo);
    }

    public function testBindsInterfaceViaFactoryClosure(): void
    {
        $c = new Container();
        $c->bind(Clock::class, fn (Container $_) => new FrozenClock('2026-01-01'));

        $clock = $c->get(Clock::class);
        $this->assertInstanceOf(FrozenClock::class, $clock);
        $this->assertSame('2026-01-01', $clock->now());
    }

    public function testBoundConcreteIsAutowiredAsConstructorDep(): void
    {
        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);

        // Timestamper takes Clock in its constructor; autowiring must
        // resolve it via the binding, not try to instantiate the
        // interface.
        $stamper = $c->get(Timestamper::class);
        $this->assertInstanceOf(Timestamper::class, $stamper);
    }

    public function testRebindingOverwrites(): void
    {
        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);
        $c->bind(Clock::class, fn () => new FrozenClock('fixed'));

        $clock = $c->get(Clock::class);
        $this->assertInstanceOf(FrozenClock::class, $clock);
    }

    public function testFactoryReceivesContainer(): void
    {
        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);
        $c->bind(UserRepo::class, function (Container $inner) {
            $inner->get(Clock::class); // proves the container is passed
            return new MemoryUserRepo();
        });

        $this->assertInstanceOf(MemoryUserRepo::class, $c->get(UserRepo::class));
    }

    public function testUnboundInterfaceStillExplodes(): void
    {
        $c = new Container();
        $this->expectException(\Throwable::class);
        $c->get(UserRepo::class);
    }


    public function testGetReturnsTheSameInstanceOnSecondCall(): void
    {
        // Container caches each resolved type as a singleton — same
        // instance on subsequent get()s. (Previous behaviour split
        // singletons-vs-transient by extending a `Service` base
        // class; that base went away with the convention router.)
        $c = new Container();
        $a = $c->get(NeedsDefaultBag::class);
        $b = $c->get(NeedsDefaultBag::class);
        $this->assertSame($a, $b);
        $this->assertSame($a->bag, $b->bag);
    }

    public function testGetWithParametersBypassesAndDoesNotPolluteCache(): void
    {
        // The `$parameters` arg lets callers override autowired
        // ctor values for a one-off resolution. With singleton
        // caching, that override would be silently lost (or worse:
        // poison the cache so subsequent `get()` returns the
        // parameterised instance). The contract is explicit:
        //
        //   - $parameters === []  → cached singleton
        //   - $parameters !== []  → fresh instance, not cached
        //
        // Without this rule, the override flag is a footgun.
        $c = new Container();
        $first = $c->get(NeedsDefaultBag::class);

        // Pass parameters (positional, keyed by ctor-param index)
        // → fresh instance, not the cached one.
        $custom = new \Rxn\Framework\Tests\Fixture\Container\DefaultBag();
        $custom->items[] = 'override';
        $second = $c->get(NeedsDefaultBag::class, [0 => $custom]);
        $this->assertNotSame($first, $second, 'parameterised get() must not return cached singleton');
        $this->assertSame($custom, $second->bag, 'parameter override must reach the constructor');

        // Subsequent unparameterised get() still returns the
        // ORIGINAL cached singleton — the parameterised resolution
        // didn't pollute the cache.
        $third = $c->get(NeedsDefaultBag::class);
        $this->assertSame($first, $third, 'cache must be unaffected by parameterised resolution');
    }

    public function testBindReturnsSelfForChaining(): void
    {
        $c = new Container();
        $this->assertSame($c, $c->bind(Clock::class, SystemClock::class));
    }

    public function testImplementsPsr11ContainerInterface(): void
    {
        $this->assertInstanceOf(\Psr\Container\ContainerInterface::class, new Container());
    }

    public function testHasReturnsTrueForConstructibleClass(): void
    {
        // PSR-11: has() returns true iff get() would succeed. Rxn
        // autowires any constructible class, so any class-string
        // that the autoloader can find should report true even
        // before the first get() call.
        $c = new Container();
        $this->assertTrue($c->has(SystemClock::class));
        $this->assertTrue($c->has(\Rxn\Framework\Container::class));
    }

    public function testHasReturnsTrueForBoundAbstract(): void
    {
        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);
        $this->assertTrue($c->has(Clock::class));
    }

    public function testHasReturnsFalseForUnknownClass(): void
    {
        $c = new Container();
        $this->assertFalse($c->has('Definitely\\Not\\A\\Real\\ClassName'));
    }


    public function testHasReturnsFalseForClassWithRequiredScalarConstructorParameter(): void
    {
        $c = new Container();
        $this->assertFalse($c->has(Logger::class));
    }

    public function testHasReturnsFalseForUnboundInterfaceDependency(): void
    {
        // Timestamper's constructor requires Clock (an unbound interface).
        // get(Timestamper) would throw, so has() must return false.
        $c = new Container();
        $this->assertFalse($c->has(Timestamper::class), 'unbound interface dep must make has() return false');

        // Once Clock is bound, the dependency is resolvable and has() returns true.
        $c->bind(Clock::class, SystemClock::class);
        $this->assertTrue($c->has(Timestamper::class), 'bound dep must allow has() to return true');
    }

    public function testHasReturnsFalseForAbstractClass(): void
    {
        // PSR-11: has() must return false when get() would fail.
        // Abstract classes satisfy class_exists() but cannot be
        // instantiated by the autowirer, so has() must return false
        // unless there is an explicit binding.
        $c = new Container();
        $this->assertFalse($c->has(Clock::class), 'unbound abstract must return false');

        // Once bound, has() returns true again.
        $c->bind(Clock::class, SystemClock::class);
        $this->assertTrue($c->has(Clock::class), 'bound abstract must return true');
    }

    public function testGetThrowsPsr11NotFoundExceptionForMissingClass(): void
    {
        $c = new Container();
        try {
            $c->get('Definitely\\Not\\A\\Real\\ClassName');
            $this->fail('expected NotFoundExceptionInterface');
        } catch (\Psr\Container\NotFoundExceptionInterface $e) {
            // PSR-11 consumers catch the standard interface; a
            // missing-entry case must satisfy that contract or
            // libraries that catch only NotFoundExceptionInterface
            // (and not the broader ContainerExceptionInterface)
            // will leak an unintended exception.
            $this->assertInstanceOf(ContainerException::class, $e);
        }
    }

    public function testContainerExceptionImplementsPsr11Interface(): void
    {
        $this->assertInstanceOf(
            \Psr\Container\ContainerExceptionInterface::class,
            new ContainerException('test')
        );
    }

    // -------- factory-cache dump (Tier A: eval → require) --------

    /**
     * Per-test dump dir. setUp() creates one; tearDown() clears
     * it and unsets the static cacheDir so other tests don't
     * inherit dump-dir state.
     */
    private string $dumpDir = '';

    protected function setUp(): void
    {
        $this->dumpDir = sys_get_temp_dir() . '/rxn-container-cache-' . bin2hex(random_bytes(4));
        @mkdir($this->dumpDir, 0770, true);
    }

    protected function tearDown(): void
    {
        Container::useCacheDir(null);
        Container::clearCache();
        if ($this->dumpDir !== '' && is_dir($this->dumpDir)) {
            foreach (glob($this->dumpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dumpDir);
        }
    }

    public function testCacheDirDefaultsToNull(): void
    {
        Container::useCacheDir(null);
        $this->assertNull(Container::cacheDir());
    }

    public function testUseCacheDirRejectsMissingPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Container::useCacheDir('/definitely/not/a/real/path/' . bin2hex(random_bytes(4)));
    }

    public function testFactoryDumpsToFileWhenCacheDirSet(): void
    {
        Container::useCacheDir($this->dumpDir);
        Container::clearCache();

        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);
        $stamper = $c->get(Timestamper::class);

        $this->assertInstanceOf(Timestamper::class, $stamper);
        $files = glob($this->dumpDir . '/*.php') ?: [];
        $this->assertNotEmpty($files, 'factory should have been dumped to disk');

        $contents = file_get_contents($files[0]);
        $this->assertStringContainsString('Timestamper', $contents);
        $this->assertStringStartsWith("<?php\n", $contents);
    }

    public function testCacheFileIsContentAddressedAndIdempotent(): void
    {
        Container::useCacheDir($this->dumpDir);
        Container::clearCache();

        $c1 = new Container();
        $c1->bind(Clock::class, SystemClock::class);
        $c1->get(Timestamper::class);

        $files1 = glob($this->dumpDir . '/*.php') ?: [];
        $this->assertCount(1, $files1);
        $mtime1 = filemtime($files1[0]);

        // Wipe in-memory cache only — file on disk stays. A second
        // container should reuse the existing file (no re-write).
        $reflection  = new \ReflectionClass(Container::class);
        $factoryProp = $reflection->getProperty('factoryCache');
        $factoryProp->setAccessible(true);
        $factoryProp->setValue(null, []);

        clearstatcache();
        $c2 = new Container();
        $c2->bind(Clock::class, SystemClock::class);
        $c2->get(Timestamper::class);

        $files2 = glob($this->dumpDir . '/*.php') ?: [];
        $this->assertSame($files1, $files2, 'no new file should appear');
        clearstatcache();
        $this->assertSame($mtime1, filemtime($files2[0]), 'existing file must not be rewritten');
    }

    public function testFallsBackToEvalWhenCacheDirNotSet(): void
    {
        Container::useCacheDir(null);
        Container::clearCache();

        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);
        $stamper = $c->get(Timestamper::class);

        $this->assertInstanceOf(Timestamper::class, $stamper);
        $this->assertEmpty(
            glob($this->dumpDir . '/*.php') ?: [],
            'no files should land in the dump dir when useCacheDir is null',
        );
    }

    public function testClearCacheRemovesDumpedFiles(): void
    {
        Container::useCacheDir($this->dumpDir);
        Container::clearCache();

        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);
        $c->get(Timestamper::class);

        $this->assertNotEmpty(glob($this->dumpDir . '/*.php'));

        Container::clearCache();
        $this->assertSame([], glob($this->dumpDir . '/*.php'));
    }

    public function testDumpedFactoryProducesCorrectInstance(): void
    {
        Container::useCacheDir($this->dumpDir);
        Container::clearCache();

        $c = new Container();
        $c->bind(Clock::class, SystemClock::class);

        $stamper = $c->get(Timestamper::class);
        $this->assertInstanceOf(Timestamper::class, $stamper);
        // The Clock dep arrives via the dumped factory's
        // $c->get(Clock::class) call — exercise it through the
        // public method to confirm the factory wired it up.
        $this->assertStringStartsWith('hello@', $stamper->stamp('hello'));
    }
}
