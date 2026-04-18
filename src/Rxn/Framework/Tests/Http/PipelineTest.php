<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Middleware;
use Rxn\Framework\Http\Pipeline;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

final class PipelineTest extends TestCase
{
    private function request(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }

    private function response(): Response
    {
        return (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
    }

    /**
     * Build an anonymous Middleware that records its name into a
     * shared log on the way in and on the way out. Useful for
     * asserting execution order.
     */
    private function recorder(string $name, array &$log): Middleware
    {
        return new class($name, $log) implements Middleware {
            public function __construct(private string $name, private array &$log) {}

            public function handle(Request $request, callable $next): Response
            {
                $this->log[] = $this->name . ':before';
                $response    = $next($request);
                $this->log[] = $this->name . ':after';
                return $response;
            }
        };
    }

    public function testTerminalRunsWhenNoMiddleware(): void
    {
        $response = $this->response();
        $result   = (new Pipeline())->handle($this->request(), fn () => $response);
        $this->assertSame($response, $result);
    }

    public function testMiddlewareExecutesInRegistrationOrder(): void
    {
        $log      = [];
        $pipeline = (new Pipeline())
            ->add($this->recorder('one', $log))
            ->add($this->recorder('two', $log))
            ->add($this->recorder('three', $log));

        $pipeline->handle($this->request(), function () use (&$log) {
            $log[] = 'terminal';
            return $this->response();
        });

        $this->assertSame(
            ['one:before', 'two:before', 'three:before', 'terminal', 'three:after', 'two:after', 'one:after'],
            $log
        );
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $short       = $this->response();
        $terminalHit = false;

        $blocker = new class($short) implements Middleware {
            public function __construct(private Response $short) {}
            public function handle(Request $request, callable $next): Response
            {
                return $this->short;
            }
        };

        $pipeline = (new Pipeline())->add($blocker);
        $result   = $pipeline->handle($this->request(), function () use (&$terminalHit) {
            $terminalHit = true;
            return (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
        });

        $this->assertSame($short, $result);
        $this->assertFalse($terminalHit, 'terminal must not run when an earlier middleware short-circuits');
    }

    public function testExceptionPropagatesOutOfPipeline(): void
    {
        $thrower = new class implements Middleware {
            public function handle(Request $request, callable $next): Response
            {
                throw new \RuntimeException('nope');
            }
        };

        $pipeline = (new Pipeline())->add($thrower);
        $this->expectException(\RuntimeException::class);
        $pipeline->handle($this->request(), fn () => (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor());
    }

    public function testRequestFlowsThroughEachMiddleware(): void
    {
        $seen = [];
        $tag  = new class($seen) implements Middleware {
            public function __construct(private array &$seen) {}
            public function handle(Request $request, callable $next): Response
            {
                $this->seen[] = spl_object_id($request);
                return $next($request);
            }
        };

        $req      = $this->request();
        $pipeline = (new Pipeline())->add($tag)->add($tag);
        $pipeline->handle($req, fn (Request $r) => $this->response());

        $this->assertSame([spl_object_id($req), spl_object_id($req)], $seen);
    }
}
