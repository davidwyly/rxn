<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Observability;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Rxn\Framework\App;
use Rxn\Framework\Event\EventDispatcher;
use Rxn\Framework\Event\ListenerProvider;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Observability\Event\FrameworkEvent;
use Rxn\Framework\Observability\Event\HandlerInvoked;
use Rxn\Framework\Observability\Event\RequestReceived;
use Rxn\Framework\Observability\Event\ResponseEmitted;
use Rxn\Framework\Observability\Event\RouteMatched;
use Rxn\Framework\Observability\Events;

/**
 * Integration tests for `App::serve()` event emission.
 *
 * These tests run `App::serve()` against synthetic `$_SERVER`
 * state and capture the response with `ob_start()` so the
 * `PsrAdapter::emit()` call (echo + header()) is harmless in the
 * CLI/PHPUnit context. Five things on trial:
 *
 *   1. The success path emits `RequestReceived` →
 *      `RouteMatched` → `HandlerInvoked(entered)` →
 *      `HandlerInvoked(exited)` → `ResponseEmitted`, all
 *      sharing a single pair id where applicable.
 *   2. The 404 / 405 miss path emits `RequestReceived` and
 *      `ResponseEmitted` (no `RouteMatched`, no
 *      `HandlerInvoked`) — the listener can still close the
 *      request span.
 *   3. The pair id flows into Router events via
 *      `Events::currentPairId()`.
 *   4. The pair-id slot is cleared in `finally`, so a subsequent
 *      request doesn't see the previous request's id.
 *   5. With no dispatcher installed, `App::serve()` doesn't mint
 *      a pair id and emits nothing — verifying the no-op
 *      guarantee that the docstring promises.
 */
final class AppServeEventsTest extends TestCase
{
    /** @var list<FrameworkEvent> */
    private array $captured = [];
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->captured     = [];
        $this->serverBackup = $_SERVER;

        $provider = new ListenerProvider();
        $provider->listen(FrameworkEvent::class, function (object $e): void {
            $this->captured[] = $e;
        });
        Events::useDispatcher(new EventDispatcher($provider));
    }

    protected function tearDown(): void
    {
        Events::useDispatcher(null);
        Events::useCurrentPairId(null);
        $_SERVER = $this->serverBackup;
    }

    public function testSuccessfulRequestEmitsFullEventTree(): void
    {
        $router = new Router();
        $router->get('/users/{id:int}', static fn (array $params) => new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['id' => $params['id']]),
        ));

        $this->setRequest('GET', '/users/42');

        ob_start();
        try {
            App::serve($router, static function (array $hit, $req) {
                $handler = $hit['handler'];
                return $handler($hit['params'] ?? [], $req);
            });
        } finally {
            ob_end_clean();
        }

        $types = array_map(static fn ($e) => $e::class, $this->captured);
        $this->assertSame(
            [
                RequestReceived::class,
                RouteMatched::class,
                HandlerInvoked::class, // entered
                HandlerInvoked::class, // exited
                ResponseEmitted::class,
            ],
            $types,
            'event ordering must match the documented lifecycle',
        );

        /** @var RequestReceived $req */
        $req = $this->captured[0];
        /** @var RouteMatched $route */
        $route = $this->captured[1];
        /** @var HandlerInvoked $entered */
        $entered = $this->captured[2];
        /** @var HandlerInvoked $exited */
        $exited = $this->captured[3];
        /** @var ResponseEmitted $resp */
        $resp = $this->captured[4];

        $pairId = $req->pairId;
        $this->assertNotSame('', $pairId);
        $this->assertSame($pairId, $route->pairId, 'RouteMatched must carry the request pair id');
        $this->assertSame($pairId, $entered->pairId);
        $this->assertSame($pairId, $exited->pairId);
        $this->assertSame($pairId, $resp->pairId);

        $this->assertSame('/users/{id:int}', $route->template);
        $this->assertSame(['id' => '42'], $route->params);

        $this->assertSame(HandlerInvoked::STATE_ENTERED, $entered->state);
        $this->assertSame(HandlerInvoked::STATE_EXITED, $exited->state);
        $this->assertNull($exited->throwable);

        $this->assertSame(200, $resp->response->getStatusCode());
    }

    public function testRouteMissEmitsRequestReceivedAndResponseEmittedOnly(): void
    {
        $router = new Router();
        $router->get('/users/{id:int}', static fn () => new Response(200));
        // Different path — listener should still see request bookends.
        $this->setRequest('GET', '/no/such/path');

        ob_start();
        try {
            App::serve($router);
        } finally {
            ob_end_clean();
        }

        $types = array_map(static fn ($e) => $e::class, $this->captured);
        $this->assertSame(
            [RequestReceived::class, ResponseEmitted::class],
            $types,
            '404 path: just request bookends, no RouteMatched/Handler events',
        );

        /** @var RequestReceived $req */
        $req = $this->captured[0];
        /** @var ResponseEmitted $resp */
        $resp = $this->captured[1];

        $this->assertSame($req->pairId, $resp->pairId);
        $this->assertSame(404, $resp->response->getStatusCode());
    }

    public function testMethodMismatchEmits405WithoutRouteMatched(): void
    {
        $router = new Router();
        $router->get('/users/me', static fn () => new Response(200));
        $this->setRequest('POST', '/users/me');

        ob_start();
        try {
            App::serve($router);
        } finally {
            ob_end_clean();
        }

        /** @var ResponseEmitted $resp */
        $resp = $this->captured[1] ?? null;
        $this->assertNotNull($resp);
        $this->assertSame(405, $resp->response->getStatusCode());

        $hadRouteMatched = false;
        foreach ($this->captured as $ev) {
            if ($ev instanceof RouteMatched) {
                $hadRouteMatched = true;
            }
        }
        $this->assertFalse($hadRouteMatched, '405 must NOT emit RouteMatched (nothing matched)');
    }

    public function testPairIdSlotIsClearedAfterRequest(): void
    {
        $router = new Router();
        $router->get('/x', static fn () => new Response(200));
        $this->setRequest('GET', '/x');

        ob_start();
        try {
            App::serve($router);
        } finally {
            ob_end_clean();
        }

        // After the request, the slot must be back to null —
        // otherwise the next request in a long-running worker
        // would inherit the previous pair id.
        $this->assertNull(Events::currentPairId());
    }

    public function testNoEventsEmittedWhenDispatcherIsAbsent(): void
    {
        // Detach the dispatcher BEFORE calling serve(). This is
        // the production no-op path: apps that don't subscribe
        // pay nothing.
        Events::useDispatcher(null);

        $router = new Router();
        $router->get('/x', static fn () => new Response(200));
        $this->setRequest('GET', '/x');

        ob_start();
        try {
            App::serve($router, static function (array $hit, $req) {
                return ($hit['handler'])($hit['params'] ?? [], $req);
            });
        } finally {
            ob_end_clean();
        }

        $this->assertSame([], $this->captured);
        $this->assertNull(Events::currentPairId(), 'no slot mutation when disabled');
    }

    private function setRequest(string $method, string $path): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        $_SERVER['HTTP_HOST']      = 'test.local';
        $_SERVER['SERVER_NAME']    = 'test.local';
        $_SERVER['SERVER_PORT']    = '80';
        $_SERVER['HTTPS']          = '';
        $_SERVER['QUERY_STRING']   = '';
    }
}
