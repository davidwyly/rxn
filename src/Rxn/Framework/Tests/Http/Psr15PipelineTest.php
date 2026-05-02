<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Psr15Pipeline;

final class Psr15PipelineTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    private function request(): ServerRequestInterface
    {
        return $this->factory->createServerRequest('GET', '/');
    }

    private function response(string $body = 'ok', int $status = 200): ResponseInterface
    {
        return $this->factory->createResponse($status)
            ->withBody($this->factory->createStream($body));
    }

    private function terminal(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function recorder(string $name, array &$log): MiddlewareInterface
    {
        return new class($name, $log) implements MiddlewareInterface {
            public function __construct(private string $name, private array &$log) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->log[] = $this->name . ':before';
                $response    = $handler->handle($request);
                $this->log[] = $this->name . ':after';
                return $response;
            }
        };
    }

    public function testTerminalRunsWhenNoMiddleware(): void
    {
        $expected = $this->response();
        $result   = (new Psr15Pipeline())->run($this->request(), $this->terminal($expected));
        $this->assertSame($expected, $result);
    }

    public function testExecutesInRegistrationOrder(): void
    {
        $log      = [];
        $pipeline = (new Psr15Pipeline())
            ->add($this->recorder('one', $log))
            ->add($this->recorder('two', $log));

        $pipeline->run(
            $this->request(),
            new class($log, $this->response()) implements RequestHandlerInterface {
                public function __construct(private array &$log, private ResponseInterface $response) {}
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->log[] = 'terminal';
                    return $this->response;
                }
            }
        );

        $this->assertSame(
            ['one:before', 'two:before', 'terminal', 'two:after', 'one:after'],
            $log
        );
    }

    public function testShortCircuit(): void
    {
        $short       = $this->response('short', 401);
        $terminalHit = false;

        $blocker = new class($short) implements MiddlewareInterface {
            public function __construct(private ResponseInterface $short) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->short;
            }
        };

        $pipeline = (new Psr15Pipeline())->add($blocker);
        $result   = $pipeline->run(
            $this->request(),
            new class($terminalHit, $this->response()) implements RequestHandlerInterface {
                public function __construct(private bool &$hit, private ResponseInterface $response) {}
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->hit = true;
                    return $this->response;
                }
            }
        );

        $this->assertSame($short, $result);
        $this->assertFalse($terminalHit);
    }


    public function testHandleAfterRunThrows(): void
    {
        $pipeline = new Psr15Pipeline();
        $pipeline->run($this->request(), $this->terminal($this->response()));

        $this->expectException(\LogicException::class);
        $pipeline->handle($this->request());
    }
    public function testHandleWithoutRunThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new Psr15Pipeline())->handle($this->request());
    }
}
