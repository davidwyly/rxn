<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Event\EventDispatcher;
use Rxn\Framework\Event\ListenerProvider;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Observability\Event\FrameworkEvent;
use Rxn\Framework\Observability\Event\RouteMatched;
use Rxn\Framework\Observability\Events;

/**
 * `Router::match()` emits `RouteMatched` on success — both the
 * static-path fast lane and the regex bucket. No event fires on
 * a miss (so listeners can detect "no route" by absence).
 */
final class RouterEmitsRouteMatchedTest extends TestCase
{
    /** @var list<FrameworkEvent> */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->captured = [];
        $provider = new ListenerProvider();
        $provider->listen(RouteMatched::class, function (object $e): void {
            $this->captured[] = $e;
        });
        Events::useDispatcher(new EventDispatcher($provider));
    }

    protected function tearDown(): void
    {
        Events::useDispatcher(null);
    }

    public function testStaticRouteMatchEmitsTemplateAndEmptyParams(): void
    {
        $router = new Router();
        $router->get('/users/me', static fn () => null);

        $hit = $router->match('GET', '/users/me');
        $this->assertNotNull($hit);

        $this->assertCount(1, $this->captured);
        /** @var RouteMatched $event */
        $event = $this->captured[0];
        $this->assertSame('GET', $event->method);
        $this->assertSame('/users/me', $event->template);
        $this->assertSame([], $event->params);
    }

    public function testDynamicRouteMatchEmitsExtractedParams(): void
    {
        $router = new Router();
        $router->get('/users/{id:int}', static fn () => null);

        $hit = $router->match('GET', '/users/42');
        $this->assertNotNull($hit);

        $this->assertCount(1, $this->captured);
        /** @var RouteMatched $event */
        $event = $this->captured[0];
        $this->assertSame('/users/{id:int}', $event->template);
        $this->assertSame(['id' => '42'], $event->params);
    }

    public function testNoEventOnRouteMiss(): void
    {
        $router = new Router();
        $router->get('/users/me', static fn () => null);

        $hit = $router->match('GET', '/no/such/path');
        $this->assertNull($hit);
        $this->assertSame([], $this->captured);
    }

    public function testNoEventWhenMethodMismatchOnly(): void
    {
        // POST against a GET-only route is a 405 in the caller's
        // frame, but `match()` returns null and no RouteMatched
        // event fires (because no route matched).
        $router = new Router();
        $router->get('/users/me', static fn () => null);

        $this->assertNull($router->match('POST', '/users/me'));
        $this->assertSame([], $this->captured);
    }
}
