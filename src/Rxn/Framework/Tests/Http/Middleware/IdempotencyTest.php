<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Idempotency\FileIdempotencyStore;
use Rxn\Framework\Http\Idempotency\IdempotencyStore;
use Rxn\Framework\Http\Idempotency\Psr16IdempotencyStore;
use Rxn\Framework\Http\Idempotency\StoredResponse;
use Rxn\Framework\Http\Middleware\Idempotency;

/**
 * Covers the five paths through the Idempotency middleware against
 * each shipped backend (FileIdempotencyStore, in-memory test
 * double, Psr16IdempotencyStore via PSR-16-shaped cache).
 */
final class IdempotencyTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/rxn-idem-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testNoHeaderPassesThrough(): void
    {
        $store = $this->makeFileStore();
        $response = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: [],
            body: '',
            terminal: $this->jsonTerminal(201, ['ok' => true]),
        );
        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(['ok' => true], $body['data']);
    }

    public function testGetRequestsBypassRegardlessOfHeader(): void
    {
        $store = $this->makeFileStore();
        $response = $this->runMiddleware(
            $store,
            method: 'GET',
            headers: ['Idempotency-Key' => 'k-1'],
            body: '',
            terminal: $this->jsonTerminal(200, ['greeting' => 'hi']),
        );
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(['greeting' => 'hi'], $body['data']);
        $this->assertNull($store->get('k-1'), 'GET should not have been recorded');
    }

    public function testColdKeyStoresResponse(): void
    {
        $store = $this->makeFileStore();
        $response = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-cold'],
            body: '{"a":1}',
            terminal: $this->jsonTerminal(201, ['id' => 42]),
        );
        $this->assertSame(201, $response->getStatusCode());

        $stored = $store->get('k-cold');
        $this->assertInstanceOf(StoredResponse::class, $stored);
        $this->assertSame(201, $stored->statusCode);
        $body = json_decode($stored->body, true);
        $this->assertSame(['id' => 42], $body['data']);
    }

    public function testReplayReturnsStoredResponse(): void
    {
        $store = $this->makeFileStore();

        // First call — terminal counts invocations.
        $callCount = 0;
        $terminal = function () use (&$callCount): ResponseInterface {
            $callCount++;
            return $this->jsonResponse(201, ['id' => 7]);
        };
        $first = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-replay'],
            body: '{"a":1}',
            terminal: $terminal,
        );
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(1, $callCount);

        // Second call — same key, same body. Terminal MUST NOT run.
        $second = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-replay'],
            body: '{"a":1}',
            terminal: $terminal,
        );
        $this->assertSame(1, $callCount, 'replay must not re-run the terminal');
        $this->assertSame(201, $second->getStatusCode());
        $body = json_decode((string)$second->getBody(), true);
        $this->assertSame(['id' => 7], $body['data']);
        $this->assertSame('true', $second->getHeaderLine('Idempotent-Replayed'));
    }

    public function testFingerprintMismatchReturns400(): void
    {
        $store = $this->makeFileStore();

        $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-mismatch'],
            body: '{"a":1}',
            terminal: $this->jsonTerminal(201, ['ok' => true]),
        );

        // Same key, different body → 400 problem details.
        $response = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-mismatch'],
            body: '{"a":2}',
            terminal: $this->jsonTerminal(201, ['ok' => true]),
        );
        $this->assertSame(400, $response->getStatusCode());
        $problem = json_decode((string)$response->getBody(), true);
        $this->assertSame('idempotency_key_in_use_with_different_body', $problem['type']);
    }

    public function testConcurrentRetryReturns409(): void
    {
        $store = $this->makeFileStore();

        // Acquire the lock manually to simulate "request in-flight".
        $this->assertTrue($store->lock('k-conflict', 30));

        $response = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-conflict'],
            body: '{"a":1}',
            terminal: $this->jsonTerminal(201, ['ok' => true]),
        );
        $this->assertSame(409, $response->getStatusCode());
        $problem = json_decode((string)$response->getBody(), true);
        $this->assertSame('idempotency_key_in_use', $problem['type']);

        $store->release('k-conflict');
    }

    public function test5xxResponsesAreNotCached(): void
    {
        $store = $this->makeFileStore();

        $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-5xx'],
            body: '{"a":1}',
            terminal: $this->jsonTerminal(503, ['error' => 'down']),
        );
        $this->assertNull($store->get('k-5xx'),
            '5xx responses should not be cached so retries can hit a healthy backend');
    }

    public function testTtlExpiry(): void
    {
        $store = $this->makeFileStore();
        $store->put('k-old', new StoredResponse(
            statusCode:  201,
            headers:     ['Content-Type' => ['application/json']],
            body:        '{"data":{"ok":true}}',
            fingerprint: 'abc',
            createdAt:   time() - 100,
        ), ttlSeconds: -1); // already expired
        $this->assertNull($store->get('k-old'));
    }

    // -------- Psr16 bridge --------

    protected function loadPsr16Stub(): void
    {
        require_once __DIR__ . '/../../Fixture/Psr16Stub.php';
    }

    public function testPsr16BridgeRejectsNonCacheInterface(): void
    {
        $this->loadPsr16Stub();
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line — intentional misuse */
        new Psr16IdempotencyStore(new \stdClass());
    }

    public function testPsr16BridgeWorksWithRealCacheInterface(): void
    {
        $this->loadPsr16Stub();
        $cache = $this->makePsr16Cache();
        $store = new Psr16IdempotencyStore($cache);

        $this->assertNull($store->get('foo'));
        $store->put('foo', new StoredResponse(
            statusCode: 201,
            headers:    ['Content-Type' => ['application/json']],
            body:       '{"data":{"x":1}}',
            fingerprint: 'fp',
            createdAt:  time(),
        ), 60);
        $stored = $store->get('foo');
        $this->assertNotNull($stored);
        $this->assertSame(201, $stored->statusCode);
    }

    public function testPsr16BridgeLockSemantics(): void
    {
        $this->loadPsr16Stub();
        $cache = $this->makePsr16Cache();
        $store = new Psr16IdempotencyStore($cache);

        $this->assertTrue($store->lock('k', 30));
        $this->assertFalse($store->lock('k', 30), 'second lock attempt while held should fail');
        $store->release('k');
        $this->assertTrue($store->lock('k', 30), 'lock should be acquirable after release');
    }

    private function makePsr16Cache(): object
    {
        return eval(<<<'PHP'
            return new class implements \Psr\SimpleCache\CacheInterface {
                /** @var array<string, mixed> */
                public array $data = [];
                public function get(string $k, mixed $default = null): mixed { return $this->data[$k] ?? $default; }
                public function set(string $k, mixed $v, null|int|\DateInterval $ttl = null): bool { $this->data[$k] = $v; return true; }
                public function delete(string $k): bool { unset($this->data[$k]); return true; }
                public function clear(): bool { $this->data = []; return true; }
                public function getMultiple(iterable $keys, mixed $default = null): iterable { $out = []; foreach ($keys as $k) { $out[$k] = $this->get($k, $default); } return $out; }
                public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool { foreach ($values as $k => $v) { $this->set($k, $v, $ttl); } return true; }
                public function deleteMultiple(iterable $keys): bool { foreach ($keys as $k) { $this->delete($k); } return true; }
                public function has(string $k): bool { return array_key_exists($k, $this->data); }
            };
        PHP);
    }

    public function testFileStoreLockExpiresAfterTtl(): void
    {
        $store = $this->makeFileStore();
        $this->assertTrue($store->lock('k-stale', ttlSeconds: 1));
        $lockPath = $this->tmpDir . '/' . hash('sha256', 'k-stale') . '.lock';
        $this->assertFileExists($lockPath);
        touch($lockPath, time() - 60);
        $this->assertTrue($store->lock('k-stale', ttlSeconds: 1),
            'stale lock should be reclaimed');
    }

    public function testFileStoreRejectsBadDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        new FileIdempotencyStore('/dev/null/nope');
    }

    // -------- Helpers --------

    private function makeFileStore(): FileIdempotencyStore
    {
        return new FileIdempotencyStore($this->tmpDir);
    }

    /**
     * @param array<string, string> $headers PSR-7 header name => value
     */
    private function runMiddleware(
        IdempotencyStore $store,
        string $method,
        array $headers,
        string $body,
        callable $terminal,
    ): ResponseInterface {
        $request = new ServerRequest($method, 'http://test.local/test', $headers, $body);
        $handler = new class($terminal) implements RequestHandlerInterface {
            /** @var callable */
            private $cb;
            public function __construct(callable $cb) { $this->cb = $cb; }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->cb)($request);
            }
        };
        return (new Idempotency($store))->process($request, $handler);
    }

    private function jsonTerminal(int $status, array $data): callable
    {
        return fn (): ResponseInterface => $this->jsonResponse($status, $data);
    }

    private function jsonResponse(int $status, array $data): ResponseInterface
    {
        $body = json_encode([
            'data' => $data,
            'meta' => ['code' => $status],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new Psr7Response($status, ['Content-Type' => 'application/json'], $body);
    }
}
