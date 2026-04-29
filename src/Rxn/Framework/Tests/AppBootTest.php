<?php declare(strict_types=1);

namespace Rxn\Framework\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Rxn\Framework\App;

/**
 * Regression test for the App::__construct database-free boot
 * guarantee.
 *
 * Background: until this branch fixed it, App's constructor eagerly
 * called `$container->get(Service\Registry::class)`, which fired
 * `Registry::__construct` → `fetchTables()` → a MySQL query during
 * boot. Result: every request — including 404s and `/health`
 * checks — required the database to be reachable, even when the
 * controller didn't touch the database.
 *
 * The fix removes that eager-load. Registry's actual consumers
 * (`Model\Record`, `Data\Map`) pull it from the container on first
 * access, so apps using those code paths still work and apps that
 * don't never hit the database during boot.
 *
 * Booting App for real in a unit test is awkward — `Startup`
 * defines PHP constants and loads `.env`, both of which leak across
 * test runs. So this test asserts the regression at the source
 * level: the offending line must not be in the constructor.
 */
final class AppBootTest extends TestCase
{
    public function testConstructorDoesNotEagerlyResolveRegistry(): void
    {
        $constructor = (new ReflectionClass(App::class))->getMethod('__construct');
        $source = $this->extractMethodSource($constructor);
        // The exact line that used to be in the constructor.
        $this->assertStringNotContainsString(
            'Service\\Registry::class',
            $source,
            'App::__construct must not eagerly resolve Service\\Registry — boot would fail without a database.'
        );
        $this->assertStringNotContainsString(
            "container->get(Service\\Registry",
            $source,
            'App::__construct must not call container->get(Service\\Registry) — see ' .
            'AppBootTest::testConstructorDoesNotEagerlyResolveRegistry docblock.'
        );
    }

    public function testConstructorStillRunsStartup(): void
    {
        // Negative-companion check: the FIX shouldn't have removed
        // Startup too. Startup defines constants + loads .env and
        // is mandatory for boot.
        $constructor = (new ReflectionClass(App::class))->getMethod('__construct');
        $source = $this->extractMethodSource($constructor);
        $this->assertStringContainsString('Startup::class', $source);
    }

    private function extractMethodSource(\ReflectionMethod $method): string
    {
        $file  = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end   = $method->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
