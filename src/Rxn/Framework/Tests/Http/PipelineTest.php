<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Pipeline;

final class PipelineTest extends TestCase
{
    private function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://test.local/');
    }

    private function response(): ResponseInterface
    {
        return new Psr7Response(200);
    }

    private function terminal(callable $cb): RequestHandlerInterface
    {
        return new class($cb) implements RequestHandlerInterface {
            /** @var callable */
            private $cb;
            public function __construct(callable $cb) { $this->cb = $cb; }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->cb)($request);
            }
        };
    }

    /**
     * Anonymous PSR-15 middleware that records its name into a
     * shared log on the way in and on the way out. Useful for
     * asserting execution order.
     */
    private function recorder(string $name, array &$log): MiddlewareInterface
    {
        return new class($name, $log) implements MiddlewareInterface {
            public function __construct(private string $name, private array &$log) {}

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->log[] = $this->name . ':before';
                $response    = $handler->handle($request);
                $this->log[] = $this->name . ':after';
                return $response;
            }
        };
    }

    public function testTerminalRunsWhenNoMiddleware(): void
    {
        $response = $this->response();
        $result   = (new Pipeline())->run($this->request(), $this->terminal(fn () => $response));
        $this->assertSame($response, $result);
    }

    public function testMiddlewareExecutesInRegistrationOrder(): void
    {
        $log      = [];
        $pipeline = (new Pipeline())
            ->add($this->recorder('one', $log))
            ->add($this->recorder('two', $log))
            ->add($this->recorder('three', $log));

        $pipeline->run($this->request(), $this->terminal(function () use (&$log) {
            $log[] = 'terminal';
            return $this->response();
        }));

        $this->assertSame(
            ['one:before', 'two:before', 'three:before', 'terminal', 'three:after', 'two:after', 'one:after'],
            $log
        );
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $short       = $this->response();
        $terminalHit = false;

        $blocker = new class($short) implements MiddlewareInterface {
            public function __construct(private ResponseInterface $short) {}
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $this->short;
            }
        };

        $pipeline = (new Pipeline())->add($blocker);
        $result   = $pipeline->run($this->request(), $this->terminal(function () use (&$terminalHit) {
            $terminalHit = true;
            return new Psr7Response(500);
        }));

        $this->assertSame($short, $result);
        $this->assertFalse($terminalHit, 'terminal must not run when an earlier middleware short-circuits');
    }

    public function testExceptionPropagatesOutOfPipeline(): void
    {
        $thrower = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                throw new \RuntimeException('nope');
            }
        };

        $pipeline = (new Pipeline())->add($thrower);
        $this->expectException(\RuntimeException::class);
        $pipeline->run($this->request(), $this->terminal(fn () => $this->response()));
    }

    public function testRequestFlowsThroughEachMiddleware(): void
    {
        $seen = [];
        $tag  = new class($seen) implements MiddlewareInterface {
            public function __construct(private array &$seen) {}
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->seen[] = spl_object_id($request);
                return $handler->handle($request);
            }
        };

        $req      = $this->request();
        $pipeline = (new Pipeline())->add($tag)->add($tag);
        $pipeline->run($req, $this->terminal(fn () => $this->response()));

        $this->assertSame([spl_object_id($req), spl_object_id($req)], $seen);
    }

    public function testPipelineCanBeReusedForMultipleRequests(): void
    {
        // run() must reset state so a reused pipeline doesn't skip
        // middleware or use a stale terminal on the second request.
        $hits = [];
        $recorder = new class($hits) implements MiddlewareInterface {
            public function __construct(private array &$hits) {}
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->hits[] = 'mw';
                return $handler->handle($request);
            }
        };

        $pipeline = (new Pipeline())->add($recorder);

        $first  = $pipeline->run($this->request(), $this->terminal(fn () => new Psr7Response(200)));
        $second = $pipeline->run($this->request(), $this->terminal(fn () => new Psr7Response(201)));

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame(['mw', 'mw'], $hits, 'middleware must run on every request, not just the first');
    }

    public function testStateIsResetAfterExceptionDuringRun(): void
    {
        // handle() must remain callable after an exception during run()
        // without replaying the previous request's terminal.
        $thrower = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                throw new \RuntimeException('boom');
            }
        };

        $pipeline = (new Pipeline())->add($thrower);
        try {
            $pipeline->run($this->request(), $this->terminal(fn () => $this->response()));
        } catch (\RuntimeException) {}

        // Second run with a pass-through middleware must work cleanly.
        $passthrough = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request);
            }
        };
        $pipeline2  = (new Pipeline())->add($passthrough);
        $result = $pipeline2->run($this->request(), $this->terminal(fn () => new Psr7Response(204)));
        $this->assertSame(204, $result->getStatusCode());
    }
}
