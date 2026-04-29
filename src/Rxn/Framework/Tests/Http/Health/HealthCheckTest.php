<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Health;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Health\HealthCheck;
use Rxn\Framework\Http\Router;

final class HealthCheckTest extends TestCase
{
    public function testRunWithAllOk(): void
    {
        $result = HealthCheck::run([
            'database' => fn () => true,
            'cache'    => fn () => ['ok' => true, 'depth' => 17],
        ]);
        $this->assertSame('ok', $result['data']['status']);
        $this->assertSame(['status' => 'ok'], $result['data']['checks']['database']);
        $this->assertSame(['status' => 'ok', 'ok' => true, 'depth' => 17], $result['data']['checks']['cache']);
        $this->assertSame(200, $result['meta']['status']);
    }

    public function testRunWithFailingCheck(): void
    {
        $result = HealthCheck::run([
            'database' => fn () => true,
            'queue'    => fn () => false,
        ]);
        $this->assertSame('fail', $result['data']['status']);
        $this->assertSame(['status' => 'ok'],   $result['data']['checks']['database']);
        $this->assertSame(['status' => 'fail'], $result['data']['checks']['queue']);
        $this->assertSame(503, $result['meta']['status']);
    }

    public function testThrowingCheckCapturedAsFail(): void
    {
        $result = HealthCheck::run([
            'database' => fn () => throw new \RuntimeException('connection refused'),
        ]);
        $this->assertSame('fail', $result['data']['status']);
        $this->assertSame('fail', $result['data']['checks']['database']['status']);
        $this->assertSame('connection refused', $result['data']['checks']['database']['error']);
    }

    public function testCheckReturningArrayWithExplicitFailStatus(): void
    {
        $result = HealthCheck::run([
            'redis' => fn () => ['status' => 'fail', 'reason' => 'too slow'],
        ]);
        $this->assertSame('fail', $result['data']['status']);
        $this->assertSame('fail', $result['data']['checks']['redis']['status']);
        $this->assertSame('too slow', $result['data']['checks']['redis']['reason']);
    }

    public function testEmptyChecksReportsOk(): void
    {
        $result = HealthCheck::run([]);
        $this->assertSame('ok', $result['data']['status']);
        $this->assertSame([], $result['data']['checks']);
    }

    public function testRegisterAddsRoute(): void
    {
        $router = new Router();
        HealthCheck::register($router, '/health', ['db' => fn () => true]);

        $hit = $router->match('GET', '/health');
        $this->assertNotNull($hit);
        $handler = $hit['handler'];
        $this->assertIsCallable($handler);

        $body = $handler();
        $this->assertSame('ok', $body['data']['status']);
    }

    public function testRegisterReturnsRouteForChainingMiddleware(): void
    {
        $router = new Router();
        $route  = HealthCheck::register($router, '/admin/health');
        // The Router's `get()` returns a Route; we can chain
        // ->name() and ->middleware() on it.
        $route->name('health.admin');
        // Verify the name was applied.
        $this->assertSame('/admin/health', $router->url('health.admin'));
    }
}
