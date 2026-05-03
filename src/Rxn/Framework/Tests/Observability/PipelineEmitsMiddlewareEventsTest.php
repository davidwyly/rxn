<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Observability;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Event\EventDispatcher;
use Rxn\Framework\Event\ListenerProvider;
use Rxn\Framework\Http\Pipeline;
use Rxn\Framework\Observability\Event\FrameworkEvent;
use Rxn\Framework\Observability\Event\MiddlewareEntered;
use Rxn\Framework\Observability\Event\MiddlewareExited;
use Rxn\Framework\Observability\Events;

/**
 * Integration tests for `Pipeline` event emission. Three things
 * on trial:
 *
 *   1. Each middleware in the stack produces exactly one
 *      Entered/Exited pair, and they share a `pairId`.
 *   2. The `index` field matches the middleware's position in the
 *      stack.
 *   3. When a middleware throws, an Exited event STILL fires —
 *      the listener can close the open span instead of leaking
 *      it.
 */
final class PipelineEmitsMiddlewareEventsTest extends TestCase
{
    /** @var list<FrameworkEvent> */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->captured = [];
        $provider = new ListenerProvider();
        $provider->listen(FrameworkEvent::class, function (object $e): void {
            $this->captured[] = $e;
        });
        Events::useDispatcher(new EventDispatcher($provider));
    }

    protected function tearDown(): void
    {
        Events::useDispatcher(null);
    }

    public function testEmitsOneEnteredExitedPairPerMiddlewareInOrder(): void
    {
        $pipeline = (new Pipeline())
            ->add($this->makeMiddleware('A'))
            ->add($this->makeMiddleware('B'));

        $terminal = $this->terminalReturning(new Response(200));
        $pipeline->run(new ServerRequest('GET', '/'), $terminal);

        $this->assertCount(4, $this->captured, '2 middlewares × Entered+Exited = 4 events');

        // Order: A entered, B entered, B exited, A exited
        // (LIFO: each middleware completes before the previous returns).
        [$e0, $e1, $e2, $e3] = $this->captured;

        $this->assertInstanceOf(MiddlewareEntered::class, $e0);
        $this->assertInstanceOf(MiddlewareEntered::class, $e1);
        $this->assertInstanceOf(MiddlewareExited::class, $e2);
        $this->assertInstanceOf(MiddlewareExited::class, $e3);

        $this->assertSame(0, $e0->index, 'first Entered is index 0');
        $this->assertSame(1, $e1->index, 'second Entered is index 1');
        $this->assertSame(1, $e2->index, 'inner Exited matches inner Entered');
        $this->assertSame(0, $e3->index, 'outer Exited matches outer Entered');
    }

    public function testEnteredAndExitedShareAPairId(): void
    {
        $pipeline = (new Pipeline())
            ->add($this->makeMiddleware('Only'));

        $pipeline->run(new ServerRequest('GET', '/'), $this->terminalReturning(new Response(200)));

        $this->assertCount(2, $this->captured);
        /** @var MiddlewareEntered $entered */
        $entered = $this->captured[0];
        /** @var MiddlewareExited $exited */
        $exited  = $this->captured[1];

        $this->assertNotSame('', $entered->pairId);
        $this->assertSame($entered->pairId, $exited->pairId);
    }

    public function testExitedFiresEvenWhenMiddlewareThrows(): void
    {
        // A listener building a span tree must always close every
        // span it opened — so even when the middleware throws,
        // `MiddlewareExited` must fire (with `$throwable` set).
        $thrower = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };
        $pipeline = (new Pipeline())->add($thrower);

        try {
            $pipeline->run(new ServerRequest('GET', '/'), $this->terminalReturning(new Response(200)));
            $this->fail('exception was expected to propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(2, $this->captured);
        /** @var MiddlewareExited $exited */
        $exited = $this->captured[1];
        $this->assertNull($exited->response, 'no response when middleware threw');
        $this->assertNotNull($exited->throwable);
        $this->assertSame('boom', $exited->throwable->getMessage());
    }

    public function testMiddlewareClassFieldIsTheConcreteFqcn(): void
    {
        $named = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
        $cls      = $named::class;
        $pipeline = (new Pipeline())->add($named);
        $pipeline->run(new ServerRequest('GET', '/'), $this->terminalReturning(new Response(200)));

        /** @var MiddlewareEntered $entered */
        $entered = $this->captured[0];
        $this->assertSame($cls, $entered->middlewareClass);
    }

    public function testNoEventsEmittedWhenDispatcherIsAbsent(): void
    {
        // The real call sites all flow through `Events::emit()`,
        // which short-circuits when no dispatcher is installed.
        // Belt-and-braces: tear it down and verify Pipeline still
        // works (and silently doesn't try to dispatch).
        Events::useDispatcher(null);

        $pipeline = (new Pipeline())->add($this->makeMiddleware('A'));
        $response = $pipeline->run(new ServerRequest('GET', '/'), $this->terminalReturning(new Response(204)));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $this->captured);
    }

    private function makeMiddleware(string $tag): MiddlewareInterface
    {
        return new class($tag) implements MiddlewareInterface {
            public function __construct(private string $tag) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute('mw-' . $this->tag, true));
            }
        };
    }

    private function terminalReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
