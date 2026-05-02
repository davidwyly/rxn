<?php declare(strict_types=1);

namespace Rxn\Framework\Testing;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Pipeline;
use Rxn\Framework\Http\Router;

/**
 * Fluent in-process HTTP client for testing. Matches the incoming
 * (method, path) against a Router, runs the resulting middleware
 * stack, and hands the terminal to a caller-supplied dispatcher —
 * so tests assert end-to-end (routing + middleware + handler)
 * without spawning a web server.
 *
 *   $client = new TestClient($router, function (array $hit, ServerRequestInterface $req) use ($container) {
 *       [$class, $method] = $hit['handler'];  // from #[Route] scanner
 *       $body = $container->get($class)->{$method}(...array_values($hit['params']));
 *       return new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($body));
 *   });
 *
 *   $client->get('/products/42')
 *          ->assertStatus(200)
 *          ->assertJsonPath('data.id', 42);
 *
 * The dispatcher's contract is:
 *   fn(array $hit, ServerRequestInterface $request): ResponseInterface
 * where `$hit` is the `Router::match()` payload. Tests that only
 * exercise middleware can pass a trivial dispatcher that returns
 * a canned ResponseInterface.
 */
final class TestClient
{
    /** @var \Closure(array, ServerRequestInterface): ResponseInterface */
    private \Closure $dispatcher;

    /** @var array<string, string> */
    private array $defaultHeaders = [];

    /** @param callable(array, ServerRequestInterface): ResponseInterface $dispatcher */
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
     * @param string                $method
     * @param string                $path   may include a query string
     * @param array|null            $body   serialised to JSON for the request body
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, ?array $body, array $headers): TestResponse
    {
        $headers = array_merge($this->defaultHeaders, $headers);
        $this->populateSuperglobals($method, $path, $body ?? [], $headers);

        [$pathOnly, $query] = self::splitPath($path);

        $hit = $this->router->match($method, $pathOnly);
        if ($hit === null) {
            $status = $this->router->hasMethodMismatch($method, $pathOnly) ? 405 : 404;
            return new TestResponse(self::problem($status));
        }

        $request = $this->buildPsrRequest($method, $path, $query, $body, $headers);

        $pipeline = new Pipeline();
        foreach ($hit['middlewares'] as $mw) {
            $pipeline->add($mw);
        }

        $dispatcher = $this->dispatcher;
        $terminal = new class($dispatcher, $hit) implements RequestHandlerInterface {
            public function __construct(private \Closure $dispatcher, private array $hit) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->dispatcher)($this->hit, $request);
            }
        };

        return new TestResponse($pipeline->run($request, $terminal));
    }

    /**
     * @param array<string, string>  $query
     * @param array<string, mixed>|null $body
     * @param array<string, string>  $headers
     */
    private function buildPsrRequest(
        string $method,
        string $path,
        array $query,
        ?array $body,
        array $headers,
    ): ServerRequestInterface {
        $uri = 'http://test.local' . $path;

        // Implicitly set Content-Type: application/json when sending a
        // body as a PHP array and the caller hasn't specified a type.
        // This mirrors what real HTTP clients do and ensures JsonBody
        // (or similar) middleware sees the correct content-type.
        if ($body !== null && self::headerValue($headers, 'content-type') === null) {
            $headers['Content-Type'] = 'application/json';
        }

        $jsonBody = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;

        $request = new ServerRequest($method, $uri, $headers, $jsonBody, '1.1', $_SERVER);
        $request = $request->withQueryParams($query);
        if ($body !== null) {
            // Only pre-populate parsedBody for form submissions — this matches
            // PsrAdapter::serverRequestFromGlobals() production behaviour.
            // JSON requests must go through JsonBody (or similar) middleware;
            // tests that skip that middleware will correctly see null from
            // getParsedBody() rather than silently receiving a pre-parsed value.
            $contentType = strtolower(trim(explode(';', self::headerValue($headers, 'content-type') ?? '', 2)[0]));
            if ($contentType === 'application/x-www-form-urlencoded'
                || $contentType === 'multipart/form-data'
            ) {
                $request = $request->withParsedBody($body);
            }
        }
        return $request;
    }

    /**
     * Case-insensitive header lookup over an assoc array.
     *
     * @param array<string, string> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower($k) === $needle) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Populate $_SERVER / $_GET / $_POST so middleware that still
     * reads from globals (during transition) sees the same shape
     * as a real request. New PSR-15 middleware should read from
     * the ServerRequest instead.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function populateSuperglobals(string $method, string $path, array $body, array $headers): void
    {
        [$pathOnly, $query] = self::splitPath($path);

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

    private static function problem(int $status): ResponseInterface
    {
        $title = match ($status) {
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            default => 'Error',
        };
        $body = json_encode([
            'type'   => 'about:blank',
            'title'  => $title,
            'status' => $status,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new Psr7Response(
            $status,
            ['Content-Type' => 'application/problem+json'],
            $body,
        );
    }
}
