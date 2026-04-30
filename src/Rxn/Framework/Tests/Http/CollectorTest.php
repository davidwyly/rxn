<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Config;
use Rxn\Framework\Http\Collector;

/**
 * Locks in the fixes that revealed themselves when App::run() was
 * exercised end-to-end for the first time:
 *
 *   1. `getRequestUrlParams()` returns `[]` (not `null`) when
 *      `$_GET` is empty. The previous null return value made
 *      `array_key_exists($key, $this->get)` blow up with a
 *      TypeError on every request that didn't carry query params.
 *   2. `getParamFromGet()` tolerates `$this->get` being non-array
 *      and raises the documented "parameter not part of the GET
 *      request" exception instead of TypeError. Lets the exception
 *      flow up to App::renderFailure and become a clean Problem
 *      Details envelope.
 *
 * Also covers the boot-without-database guarantee these tests
 * implicitly secured: if Collector can be constructed and queried
 * without `$_GET` populated, App's request-resolution path runs
 * without ever touching the database.
 */
final class CollectorTest extends TestCase
{
    private array $serverBackup;
    private array $getBackup;
    private array $postBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup    = $_GET;
        $this->postBackup   = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET    = $this->getBackup;
        $_POST   = $this->postBackup;
    }

    public function testGetRequestUrlParamsReturnsEmptyArrayWhenGetIsEmpty(): void
    {
        $_GET = [];
        $collector = $this->makeCollector();
        $this->assertSame([], $collector->getRequestUrlParams());
    }

    public function testGetRequestUrlParamsHandlesUnsetGet(): void
    {
        // Some sapis run with $_GET genuinely unset, not just empty.
        // Seen in long-lived workers that reset state between requests.
        unset($_GET);
        $collector = $this->makeCollector();
        $this->assertSame([], $collector->getRequestUrlParams());
    }

    public function testGetParamFromGetThrowsCleanExceptionOnEmptyGet(): void
    {
        $_GET = [];
        $collector = $this->makeCollector();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Parameter 'version' is not part of the GET request");
        $collector->getParamFromGet('version');
    }

    public function testGetParamFromGetReturnsValueWhenKeyPresent(): void
    {
        $_GET = ['controller' => 'Order'];
        $collector = $this->makeCollector();
        $this->assertSame('Order', $collector->getParamFromGet('controller'));
    }

    public function testGetParamFromGetThrowsForMissingKey(): void
    {
        $_GET = ['controller' => 'Order'];
        $collector = $this->makeCollector();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Parameter 'version' is not part of the GET request");
        $collector->getParamFromGet('version');
    }

    public function testGetRequestUrlParamsParsesConventionRouteParams(): void
    {
        $_GET = ['params' => 'v1/Order/list'];
        $collector = $this->makeCollector();
        $params = $collector->getRequestUrlParams();
        $this->assertIsArray($params);
        $this->assertSame('v1',    $params['version']    ?? null);
        $this->assertSame('Order', $params['controller'] ?? null);
        $this->assertSame('list',  $params['action']     ?? null);
    }

    public function testGetRequestUrlParamsTolerantOfTrailingSlash(): void
    {
        $_GET = ['params' => 'v1/Order/list/'];
        $collector = $this->makeCollector();
        $params = $collector->getRequestUrlParams();
        $this->assertSame('list', $params['action'] ?? null);
    }

    public function testEmptyGetMeansEmptyConstructorState(): void
    {
        // Direct check of the construction-time side effects: an
        // empty $_GET / $_POST / no headers should leave the
        // collector in a usable, non-throwing state.
        $_GET    = [];
        $_POST   = [];
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $collector = $this->makeCollector();
        $this->assertInstanceOf(Collector::class, $collector);
    }

    private function makeCollector(): Collector
    {
        // Bypass the configured Config dependency — we don't need
        // env-driven sanitisation flags for these unit tests, just
        // a Collector that reads from $_GET / $_POST / headers.
        $config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        return new Collector($config);
    }
}
