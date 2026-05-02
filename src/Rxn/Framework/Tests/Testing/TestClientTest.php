<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Testing;

use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Middleware\JsonBody;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Testing\TestClient;

final class TestClientTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/ok',            ['ok']);
        $r->get('/products/{id:int}', ['products.show']);
        $r->post('/products',      ['products.create'])->middleware(new JsonBody());
        $r->delete('/products/{id:int}', ['products.delete']);
        return $r;
    }

    /**
     * Build a JSON-envelope response in the framework's native shape
     * `{data, meta}` so the existing assertJsonPath / assertJsonStructure
     * tests work unchanged. The shape is what the framework's own
     * App::render produces for a success path.
     */
    private static function ok(array $data, int $status = 200): ResponseInterface
    {
        $body = json_encode([
            'data' => $data,
            'meta' => ['success' => true, 'code' => $status],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new Psr7Response(
            $status,
            ['Content-Type' => 'application/json'],
            $body,
        );
    }

    private function client(Router $router, ?\Closure $dispatch = null): TestClient
    {
        return new TestClient(
            $router,
            $dispatch ?? fn (array $hit, ServerRequestInterface $req): ResponseInterface => match ($hit['handler'][0]) {
                'ok'               => self::ok(['status' => 'ok']),
                'products.show'    => self::ok(['id' => (int)$hit['params']['id']]),
                'products.create'  => self::ok(['created' => true, 'body' => $req->getParsedBody() ?? []]),
                'products.delete'  => self::ok(['deleted' => (int)$hit['params']['id']]),
                default            => throw new \LogicException('unhandled'),
            }
        );
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
        $tag = new class($order) implements MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->order[] = 'middleware';
                return $handler->handle($request);
            }
        };

        $router = new Router();
        $router->get('/m', ['m'])->middleware($tag);

        $client = $this->client($router, function () use (&$order) {
            $order[] = 'terminal';
            return self::ok(['ok' => true]);
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
        $client = (new TestClient($router, function ($_, ServerRequestInterface $req) use (&$seen) {
            $seen = $req->getHeaderLine('Authorization');
            return self::ok([]);
        }))->withHeaders(['Authorization' => 'Bearer xyz']);

        $client->get('/h')->assertOk();
        $this->assertSame('Bearer xyz', $seen);
    }

    public function testQueryStringIsParsedIntoGet(): void
    {
        $router = new Router();
        $router->get('/search', ['s']);
        $captured = [];
        $client = new TestClient($router, function ($_, ServerRequestInterface $req) use (&$captured) {
            $captured = $req->getQueryParams();
            return self::ok([]);
        });
        $client->get('/search?q=widgets&page=2')->assertOk();

        $this->assertSame(['q' => 'widgets', 'page' => '2'], $captured);
    }

    public function testJsonBodyIsNullWithoutJsonBodyMiddleware(): void
    {
        // TestClient must NOT pre-populate parsedBody for JSON requests.
        // Without a JsonBody (or equivalent) middleware in the pipeline,
        // getParsedBody() must return null — mirroring production behaviour
        // where PsrAdapter::serverRequestFromGlobals() only sets parsedBody
        // for form-content-type POSTs.
        $router = new Router();
        $router->post('/raw', ['raw']); // No JsonBody middleware.

        $parsed = 'NOT_SET';
        $client = new TestClient($router, function ($_, ServerRequestInterface $req) use (&$parsed) {
            $parsed = $req->getParsedBody();
            return self::ok([]);
        });
        $client->post('/raw', ['key' => 'value'])->assertOk();

        $this->assertNull($parsed, 'parsedBody must be null without a JSON body parser in the middleware chain');
    }

    public function testJsonBodyIsPopulatedByJsonBodyMiddleware(): void
    {
        // With JsonBody middleware, the same request DOES get a parsed body.
        $router = new Router();
        $router->post('/json', ['json'])->middleware(new JsonBody());

        $parsed = null;
        $client = new TestClient($router, function ($_, ServerRequestInterface $req) use (&$parsed) {
            $parsed = $req->getParsedBody();
            return self::ok([]);
        });
        $client->post('/json', ['key' => 'value'])->assertOk();

        $this->assertSame(['key' => 'value'], $parsed);
    }
}
