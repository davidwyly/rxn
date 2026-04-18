<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Testing\TestClient;

final class TestClientTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/ok',            ['ok']);
        $r->get('/products/{id:int}', ['products.show']);
        $r->post('/products',      ['products.create']);
        $r->delete('/products/{id:int}', ['products.delete']);
        return $r;
    }

    private function client(Router $router, ?\Closure $dispatch = null): TestClient
    {
        return new TestClient($router, $dispatch ?? fn (array $hit, Request $req): Response => match ($hit['handler'][0]) {
            'ok'               => (new Response())->getSuccess(['status' => 'ok']),
            'products.show'    => (new Response())->getSuccess(['id' => (int)$hit['params']['id']]),
            'products.create'  => (new Response())->getSuccess(['created' => true, 'body' => $_POST]),
            'products.delete'  => (new Response())->getSuccess(['deleted' => (int)$hit['params']['id']]),
            default            => throw new \LogicException('unhandled'),
        });
    }

    public function testRoutesAndDispatches(): void
    {
        $client = $this->client($this->router());

        $client->get('/ok')
               ->assertOk()
               ->assertJsonPath('data.status', 'ok');
    }

    public function testTypedParamIsExtractedAndPassed(): void
    {
        $client = $this->client($this->router());

        $client->get('/products/42')
               ->assertOk()
               ->assertJsonPath('data.id', 42);
    }

    public function testUnknownPathIsNotFound(): void
    {
        $client = $this->client($this->router());

        $client->get('/nope')->assertNotFound();
    }

    public function testMethodMismatchIs405(): void
    {
        $client = $this->client($this->router());
        $client->put('/ok')->assertMethodNotAllowed();
    }

    public function testPostBodyReachesDispatcher(): void
    {
        $client = $this->client($this->router());

        $client->post('/products', ['name' => 'widget'])
               ->assertOk()
               ->assertJsonPath('data.body.name', 'widget');
    }

    public function testMiddlewareStackRuns(): void
    {
        $order = [];
        $tag = new class($order) implements Middleware {
            public function __construct(private array &$order) {}
            public function handle(Request $request, callable $next): Response
            {
                $this->order[] = 'middleware';
                return $next($request);
            }
        };

        $router = new Router();
        $router->get('/m', ['m'])->middleware($tag);

        $client = $this->client($router, function () use (&$order) {
            $order[] = 'terminal';
            return (new Response())->getSuccess(['ok' => true]);
        });
        $client->get('/m')->assertOk();

        $this->assertSame(['middleware', 'terminal'], $order);
    }

    public function testAssertFailureReportsStatus(): void
    {
        $client = $this->client($this->router());
        $this->expectException(AssertionFailedError::class);
        $client->get('/ok')->assertStatus(418);
    }

    public function testAssertJsonStructureValidatesShape(): void
    {
        $client = $this->client($this->router());
        $client->get('/products/7')
               ->assertJsonStructure(['data' => ['id'], 'meta' => ['success', 'code']]);
    }

    public function testWithHeadersPersistsAcrossRequests(): void
    {
        $router = new Router();
        $router->get('/h', ['h']);
        $seen = null;
        $client = (new TestClient($router, function () use (&$seen) {
            $seen = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
            return (new Response())->getSuccess([]);
        }))->withHeaders(['Authorization' => 'Bearer xyz']);

        $client->get('/h')->assertOk();
        $this->assertSame('Bearer xyz', $seen);
    }

    public function testQueryStringIsParsedIntoGet(): void
    {
        $router = new Router();
        $router->get('/search', ['s']);
        $captured = [];
        $client = new TestClient($router, function () use (&$captured) {
            $captured = $_GET;
            return (new Response())->getSuccess([]);
        });
        $client->get('/search?q=widgets&page=2')->assertOk();

        $this->assertSame(['q' => 'widgets', 'page' => '2'], $captured);
    }
}
