<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Resource;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rxn\Framework\Http\Resource\ResourceRegistrar;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Tests\Http\Resource\Fixture\CreateWidget;
use Rxn\Framework\Tests\Http\Resource\Fixture\InMemoryWidgetCrud;
use Rxn\Framework\Tests\Http\Resource\Fixture\SearchWidgets;
use Rxn\Framework\Tests\Http\Resource\Fixture\UpdateWidget;

/**
 * Integration tests for `ResourceRegistrar`. Each test drives a
 * real Router → matched handler → response shape, against an
 * in-memory `CrudHandler`. The contract on trial:
 *
 *   1. Five routes get registered with the documented HTTP
 *      methods and paths (`POST /` / `GET /` / `GET /{id:int}` /
 *      `PATCH /{id:int}` / `DELETE /{id:int}`).
 *   2. Create and update bind the request body through the
 *      Binder, surface validation failures as 422 Problem
 *      Details with `errors[]`, and pass the hydrated DTO to
 *      the handler on success.
 *   3. Search optionally binds a filter DTO from query params;
 *      registrations without a search DTO call the handler
 *      with `null`.
 *   4. Read / update return 404 Problem Details when the
 *      handler returns `null`.
 *   5. Delete returns a true PSR-7 204 (empty body) on success
 *      and 404 Problem Details on missing.
 *   6. The `idType` arg controls both the URL constraint AND
 *      the handler's id type (int vs. string).
 */
final class ResourceRegistrarTest extends TestCase
{
    private Router $router;
    private InMemoryWidgetCrud $crud;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->crud   = new InMemoryWidgetCrud();
        ResourceRegistrar::register(
            $this->router,
            '/widgets',
            $this->crud,
            create: CreateWidget::class,
            update: UpdateWidget::class,
            search: SearchWidgets::class,
        );
    }

    public function testAllFiveRoutesAreRegistered(): void
    {
        // Five routes, the documented shape. Calling match() on
        // each verifies registration; the handlers themselves
        // are tested elsewhere.
        $this->assertNotNull($this->router->match('POST',   '/widgets'),    'POST /widgets must register');
        $this->assertNotNull($this->router->match('GET',    '/widgets'),    'GET /widgets must register');
        $this->assertNotNull($this->router->match('GET',    '/widgets/1'),  'GET /widgets/{id} must register');
        $this->assertNotNull($this->router->match('PATCH',  '/widgets/1'),  'PATCH /widgets/{id} must register');
        $this->assertNotNull($this->router->match('DELETE', '/widgets/1'),  'DELETE /widgets/{id} must register');
    }

    public function testIdConstraintRejectsNonIntByDefault(): void
    {
        // Default idType is 'int' → /widgets/abc must NOT match
        // any registered route. Falls through to the framework's
        // 404 handling.
        $this->assertNull($this->router->match('GET', '/widgets/abc'));
    }

    public function testCreateRoundTripsThroughBinderToHandler(): void
    {
        $body     = ['name' => 'Widget', 'price' => 9, 'status' => 'published'];
        $response = $this->invoke('POST', '/widgets', $body);

        $this->assertSame(201, $response->getStatusCode());
        $payload = $this->decode($response);
        $this->assertSame('Widget', $payload['data']['name']);
        $this->assertSame(9, $payload['data']['price']);
        $this->assertSame('published', $payload['data']['status']);
        $this->assertSame(1, $payload['data']['id']);
    }

    public function testCreateValidationFailureReturns422WithErrors(): void
    {
        $body     = ['name' => '', 'price' => -1, 'status' => 'weird'];
        $response = $this->invoke('POST', '/widgets', $body);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $payload = $this->decode($response);
        $this->assertSame('Unprocessable Entity', $payload['title']);
        $fields  = array_column($payload['errors'], 'field');
        $this->assertContains('name', $fields);
        $this->assertContains('price', $fields);
        $this->assertContains('status', $fields);
    }

    public function testReadReturnsRowOrNullAsHandlerProvides(): void
    {
        $this->crud->create($this->buildCreate('Found', 5));

        $found = $this->invoke('GET', '/widgets/1');
        $this->assertSame(200, $found->getStatusCode());
        $this->assertSame('Found', $this->decode($found)['data']['name']);

        $missing = $this->invoke('GET', '/widgets/999');
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame('Not Found', $this->decode($missing)['title']);
    }

    public function testUpdateMergesPartialFieldsOntoExistingRow(): void
    {
        $this->crud->create($this->buildCreate('Original', 10, 'draft'));

        // PATCH only the name. Price / status stay as-is.
        $response = $this->invoke('PATCH', '/widgets/1', ['name' => 'Renamed']);

        $this->assertSame(200, $response->getStatusCode());
        $payload = $this->decode($response);
        $this->assertSame('Renamed', $payload['data']['name']);
        $this->assertSame(10,        $payload['data']['price']);
        $this->assertSame('draft',   $payload['data']['status']);
    }

    public function testUpdateOnMissingIdReturns404(): void
    {
        $response = $this->invoke('PATCH', '/widgets/999', ['name' => 'No-op']);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateValidationFailureReturns422(): void
    {
        $this->crud->create($this->buildCreate('X', 1));

        $response = $this->invoke('PATCH', '/widgets/1', ['status' => 'not-allowed']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testDeleteReturnsTrue204WithEmptyBody(): void
    {
        $this->crud->create($this->buildCreate('X', 1));

        $response = $this->invoke('DELETE', '/widgets/1');
        $this->assertSame(204, $response->getStatusCode());
        // 204 No Content per HTTP spec — body must be empty,
        // even though the framework's array envelope mapper would
        // normally produce one.
        $this->assertSame('', (string) $response->getBody());
    }

    public function testDeleteOnMissingIdReturns404(): void
    {
        $response = $this->invoke('DELETE', '/widgets/999');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSearchReturnsListWrappedInDataEnvelope(): void
    {
        $this->crud->create($this->buildCreate('Apple',  1, 'published'));
        $this->crud->create($this->buildCreate('Banana', 2, 'published'));
        $this->crud->create($this->buildCreate('Cherry', 3, 'draft'));

        $response = $this->invoke('GET', '/widgets');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(3, $this->decode($response)['data']);
    }

    public function testSearchAppliesFilterFromQueryString(): void
    {
        $this->crud->create($this->buildCreate('Apple',  1, 'published'));
        $this->crud->create($this->buildCreate('Banana', 2, 'published'));
        $this->crud->create($this->buildCreate('Cherry', 3, 'draft'));

        $response = $this->invoke('GET', '/widgets?status=draft');
        $this->assertSame(200, $response->getStatusCode());
        $rows = $this->decode($response)['data'];
        $this->assertCount(1, $rows);
        $this->assertSame('Cherry', $rows[0]['name']);
    }

    public function testSearchValidationFailureReturns422(): void
    {
        // SearchWidgets has #[InSet(['draft','published','archived'])]
        // on $status. An out-of-set value rejects the request
        // before search() runs.
        $response = $this->invoke('GET', '/widgets?status=weird');
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testNullSearchDtoCallsHandlerWithNullFilter(): void
    {
        // A separate router + registration that omits the
        // `search` arg. The handler receives null and returns
        // every row (in-memory implementation's behaviour).
        $router = new Router();
        $crud   = new InMemoryWidgetCrud();
        ResourceRegistrar::register(
            $router,
            '/items',
            $crud,
            create: CreateWidget::class,
            update: UpdateWidget::class,
        );
        $crud->create($this->buildCreate('A', 1));
        $crud->create($this->buildCreate('B', 2));

        $hit = $router->match('GET', '/items');
        $this->assertNotNull($hit);
        $result = ($hit['handler'])(
            $hit['params'],
            new ServerRequest('GET', 'http://test/items'),
        );
        $this->assertCount(2, $result['data']);
    }

    public function testIdTypeUuidProducesStringIds(): void
    {
        // Custom idType → URL placeholder uses the constraint
        // AND the handler receives a string id (not int-cast).
        // Drive a fresh registration with idType: 'uuid'.
        $router = new Router();
        $captured = null;
        $registrarHandler = new class($captured) implements \Rxn\Framework\Http\Resource\CrudHandler {
            public function __construct(public mixed &$captured) {}
            public function create(\Rxn\Framework\Http\Binding\RequestDto $dto): array { return []; }
            public function read(int|string $id): ?array
            {
                $this->captured = $id;
                return null;
            }
            public function update(int|string $id, \Rxn\Framework\Http\Binding\RequestDto $dto): ?array { return null; }
            public function delete(int|string $id): bool { return false; }
            public function search(?\Rxn\Framework\Http\Binding\RequestDto $filter): array { return []; }
        };
        ResourceRegistrar::register(
            $router,
            '/orgs',
            $registrarHandler,
            create: CreateWidget::class,
            update: UpdateWidget::class,
            idType: 'uuid',
        );

        // /orgs/abc would fail under default idType=int; with
        // idType=uuid, only valid UUIDs match.
        $this->assertNull(
            $router->match('GET', '/orgs/not-a-uuid'),
            'idType=uuid must reject non-UUID ids at the route level',
        );

        $hit = $router->match('GET', '/orgs/550e8400-e29b-41d4-a716-446655440000');
        $this->assertNotNull($hit);
        ($hit['handler'])($hit['params'], new ServerRequest('GET', 'http://test/'));

        $this->assertIsString($registrarHandler->captured);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $registrarHandler->captured);
    }

    /**
     * Resolve a matched route + invoke its handler against a
     * synthetic request, normalising the array vs ResponseInterface
     * return into a uniform PSR-7 response (mirrors what
     * `App::serve`'s default invoker does).
     *
     * @param array<string, mixed>|null $body  JSON body for
     *        POST/PATCH. The Binder reads `$_POST` via
     *        `gatherBag()` when called outside a request scope,
     *        but in this test we drive Binder::bindRequest with
     *        a real PSR-7 ServerRequest carrying parsedBody.
     */
    private function invoke(string $method, string $target, ?array $body = null): ResponseInterface
    {
        $uri    = parse_url($target);
        $path   = $uri['path']  ?? $target;
        $query  = $uri['query'] ?? '';

        $hit = $this->router->match($method, $path);
        $this->assertNotNull($hit, "$method $target did not match any route");

        $request = new ServerRequest($method, "http://test{$target}");
        if ($query !== '') {
            parse_str($query, $params);
            $request = $request->withQueryParams($params);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        $result = ($hit['handler'])($hit['params'], $request);

        if ($result instanceof ResponseInterface) {
            return $result;
        }
        // Match App::arrayToPsrResponse — mirror its behaviour
        // here so the test isn't coupled to App.
        return self::wrap((array) $result);
    }

    /**
     * Mirror of `App::arrayToPsrResponse` — kept local to the
     * test so changes to App don't accidentally hide regressions
     * here (and so the test runs without booting App).
     *
     * @param array<string, mixed> $body
     */
    private static function wrap(array $body): ResponseInterface
    {
        $status      = (int) ($body['meta']['status'] ?? 200);
        $isFailure   = $status >= 400;
        $contentType = $isFailure ? 'application/problem+json' : 'application/json';

        $payload = $isFailure
            ? array_filter([
                'type'   => $body['meta']['type']   ?? 'about:blank',
                'title'  => $body['meta']['title']  ?? '',
                'status' => $status,
                'errors' => $body['meta']['errors'] ?? null,
            ], static fn ($v) => $v !== null)
            : array_filter(
                ['data' => $body['data'] ?? null, 'meta' => $body['meta'] ?? null],
                static fn ($v) => $v !== null,
            );
        return new \Nyholm\Psr7\Response(
            $status,
            ['Content-Type' => $contentType],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $r): array
    {
        return json_decode((string) $r->getBody(), true) ?? [];
    }

    private function buildCreate(string $name, int $price, string $status = 'draft'): CreateWidget
    {
        $dto = new CreateWidget();
        $dto->name   = $name;
        $dto->price  = $price;
        $dto->status = $status;
        return $dto;
    }
}
