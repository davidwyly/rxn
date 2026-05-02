<?php declare(strict_types=1);

namespace Rxn\Framework\Testing;

use Rxn\Framework\Http\Pipeline;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;
use Rxn\Framework\Http\Router;

/**
 * Fluent in-process HTTP client for testing. Matches the incoming
 * (method, path) against a Router, runs the resulting middleware
 * stack, and hands the terminal to a caller-supplied dispatcher —
 * so tests assert end-to-end (routing + middleware + handler)
 * without spawning a web server.
 *
 *   $client = new TestClient($router, function (array $hit, Request $req) use ($container) {
 *       [$class, $method] = $hit['handler'];  // from #[Route] scanner
 *       return $container->get($class)->{$method}(...array_values($hit['params']));
 *   });
 *
 *   $client->get('/products/42')
 *          ->assertStatus(200)
 *          ->assertJsonPath('data.id', 42);
 *
 * The dispatcher's contract is:
 *   fn(array $hit, Request $request): Response
 * where `$hit` is the `Router::match()` payload. Tests that only
 * exercise middleware can pass a trivial dispatcher that returns
 * a canned Response.
 */
final class TestClient
{
    /** @var \Closure(array, Request): Response */
    private \Closure $dispatcher;

    /** @var array<string, string> */
    private array $defaultHeaders = [];

    /** @param callable(array, Request): Response $dispatcher */
    public function __construct(
        private Router $router,
        callable $dispatcher
    ) {
        $this->dispatcher = $dispatcher instanceof \Closure
            ? $dispatcher
            : \Closure::fromCallable($dispatcher);
    }

    /**
     * Register headers sent on every subsequent request — useful for
     * auth tokens in test suites that need them pervasively.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $k => $v) {
            $this->defaultHeaders[$k] = $v;
        }
        return $this;
    }

    public function get(string $path, array $headers = []): TestResponse
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $path, $body, $headers);
    }

    public function put(string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $path, $body, $headers);
    }

    public function patch(string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $path, $body, $headers);
    }

    public function delete(string $path, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $path, null, $headers);
    }

    /**
     * @param string                 $method
     * @param string                 $path   may include a query string
     * @param array|null             $body   populated into $_POST for tests
     * @param array<string, string>  $headers
     */
    private function request(string $method, string $path, ?array $body, array $headers): TestResponse
    {
        $headers = array_merge($this->defaultHeaders, $headers);
        $this->populateSuperglobals($method, $path, $body ?? [], $headers);

        [$pathOnly, $query] = self::splitPath($path);

        $hit = $this->router->match($method, $pathOnly);
        if ($hit === null) {
            if ($this->router->hasMethodMismatch($method, $pathOnly)) {
                return new TestResponse((new Response())->getFailure(new \Exception('Method Not Allowed', 405)));
            }
            return new TestResponse((new Response())->getFailure(new \Exception('Not Found', 404)));
        }

        $request = (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();

        $pipeline = new Pipeline();
        foreach ($hit['middlewares'] as $mw) {
            $pipeline->add($mw);
        }

        $dispatcher = $this->dispatcher;
        $response = $pipeline->handle(
            $request,
            static fn (Request $r) => $dispatcher($hit, $r)
        );
        return new TestResponse($response);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function populateSuperglobals(string $method, string $path, array $body, array $headers): void
    {
        [$pathOnly, $query] = self::splitPath($path);

        $this->clearRequestServerHeaders();

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        $_GET                      = $query;
        $_POST                     = is_array($body) ? $body : [];

        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $_SERVER[$key] = $value;
            if (strcasecmp($name, 'Content-Type') === 0) {
                $_SERVER['CONTENT_TYPE'] = $value;
            }
            if (strcasecmp($name, 'Content-Length') === 0) {
                $_SERVER['CONTENT_LENGTH'] = $value;
            }
        }
    }


    private function clearRequestServerHeaders(): void
    {
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with($key, 'HTTP_')) {
                unset($_SERVER[$key]);
            }
        }

        unset($_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH']);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function splitPath(string $path): array
    {
        $q = strpos($path, '?');
        if ($q === false) {
            return [$path, []];
        }
        parse_str(substr($path, $q + 1), $query);
        /** @var array<string, string> $query */
        return [substr($path, 0, $q), $query];
    }
}
