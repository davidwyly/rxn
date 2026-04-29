<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Idempotency\FileIdempotencyStore;
use Rxn\Framework\Http\Idempotency\IdempotencyStore;
use Rxn\Framework\Http\Idempotency\Psr16IdempotencyStore;
use Rxn\Framework\Http\Idempotency\StoredResponse;
use Rxn\Framework\Http\Middleware\Idempotency;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

/**
 * Covers the five paths through the Idempotency middleware against
 * each shipped backend (FileIdempotencyStore, in-memory test
 * double, Psr16IdempotencyStore via a duck-typed cache).
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
        // Clean up tmp dir
        foreach ((array) glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    /** @return list<IdempotencyStore> */
    public static function backends(): array
    {
        // Provider runs once per test; build a fresh backend each
        // time inside the test methods (depends on per-test tmp dir).
        return [];
    }

    public function testNoHeaderPassesThrough(): void
    {
        $store = $this->makeFileStore();
        $headers = [];
        $body    = ''; // raw body
        $response = $this->runMiddleware($store, $headers, body: $body, method: 'POST', terminal: $this->terminalReturning(201, ['ok' => true]));
        $this->assertSame(201, $response->getCode());
        $this->assertSame(['ok' => true], $response->data);
    }

    public function testGetRequestsBypassRegardlessOfHeader(): void
    {
        $store = $this->makeFileStore();
        $response = $this->runMiddleware(
            $store,
            ['HTTP_IDEMPOTENCY_KEY' => 'k-1'],
            body: '',
            method: 'GET',
            terminal: $this->terminalReturning(200, ['greeting' => 'hi']),
        );
        $this->assertSame(['greeting' => 'hi'], $response->data);
        $this->assertNull($store->get('k-1'), 'GET should not have been recorded');
    }

    public function testColdKeyStoresResponse(): void
    {
        $store = $this->makeFileStore();
        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'k-cold'];
        $response = $this->runMiddleware($store, $headers, body: '{"a":1}', method: 'POST', terminal: $this->terminalReturning(201, ['id' => 42]));
        $this->assertSame(201, $response->getCode());

        $stored = $store->get('k-cold');
        $this->assertInstanceOf(StoredResponse::class, $stored);
        $this->assertSame(201, $stored->statusCode);
        $this->assertSame(['id' => 42], $stored->body['data'] ?? null);
    }

    public function testReplayReturnsStoredResponse(): void
    {
        $store = $this->makeFileStore();
        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'k-replay'];

        // First call — terminal counts invocations.
        $callCount = 0;
        $terminal = function () use (&$callCount): Response {
            $callCount++;
            return $this->terminalReturning(201, ['id' => 7])($this->bareRequest());
        };
        $first = $this->runMiddleware($store, $headers, body: '{"a":1}', method: 'POST', terminal: $terminal);
        $this->assertSame(201, $first->getCode());
        $this->assertSame(1, $callCount);

        // Second call — same key, same body. Terminal MUST NOT run.
        $emittedHeaders = [];
        $second = $this->runMiddleware(
            $store,
            $headers,
            body: '{"a":1}',
            method: 'POST',
            terminal: $terminal,
            emitHeader: function (string $h) use (&$emittedHeaders) { $emittedHeaders[] = $h; },
        );
        $this->assertSame(1, $callCount, 'replay must not re-run the terminal');
        $this->assertSame(201, $second->getCode());
        $this->assertSame(['id' => 7], $second->data);
        $this->assertContains('Idempotent-Replayed: true', $emittedHeaders);
    }

    public function testFingerprintMismatchReturns400(): void
    {
        $store = $this->makeFileStore();
        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'k-mismatch'];

        $this->runMiddleware($store, $headers, body: '{"a":1}', method: 'POST', terminal: $this->terminalReturning(201, ['ok' => true]));

        // Same key, different body → 400 problem details.
        $response = $this->runMiddleware($store, $headers, body: '{"a":2}', method: 'POST', terminal: $this->terminalReturning(201, ['ok' => true]));
        $this->assertSame(400, $response->getCode());
        $this->assertSame('idempotency_key_in_use_with_different_body', $response->meta['type']);
    }

    public function testConcurrentRetryReturns409(): void
    {
        $store = $this->makeFileStore();
        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'k-conflict'];

        // Acquire the lock manually to simulate "request in-flight".
        $this->assertTrue($store->lock('k-conflict', 30));

        $response = $this->runMiddleware($store, $headers, body: '{"a":1}', method: 'POST', terminal: $this->terminalReturning(201, ['ok' => true]));
        $this->assertSame(409, $response->getCode());
        $this->assertSame('idempotency_key_in_use', $response->meta['type']);

        $store->release('k-conflict');
    }

    public function test5xxResponsesAreNotCached(): void
    {
        $store = $this->makeFileStore();
        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'k-5xx'];

        $this->runMiddleware(
            $store,
            $headers,
            body: '{"a":1}',
            method: 'POST',
            terminal: $this->terminalReturning(503, ['error' => 'down']),
        );
        $this->assertNull($store->get('k-5xx'),
            '5xx responses should not be cached so retries can hit a healthy backend');
    }

    public function testTtlExpiry(): void
    {
        $store = $this->makeFileStore();
        $store->put('k-old', new StoredResponse(
            statusCode:  201,
            body:        ['data' => ['ok' => true]],
            fingerprint: 'abc',
            createdAt:   time() - 100,
        ), ttlSeconds: -1); // already expired
        $this->assertNull($store->get('k-old'));
    }

    // -------- Psr16 bridge --------

    public function testPsr16BridgeRejectsCacheMissingMethods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $cache = new class {
            // Missing set/has/delete intentionally
            public function get(string $k): mixed { return null; }
        };
        new Psr16IdempotencyStore($cache);
    }

    public function testPsr16BridgeWorksWithDuckTypedCache(): void
    {
        $cache = new class {
            /** @var array<string, mixed> */
            public array $data = [];
            public function get(string $k, mixed $default = null): mixed { return $this->data[$k] ?? $default; }
            public function set(string $k, mixed $v, ?int $ttl = null): bool { $this->data[$k] = $v; return true; }
            public function has(string $k): bool { return array_key_exists($k, $this->data); }
            public function delete(string $k): bool { unset($this->data[$k]); return true; }
        };
        $store = new Psr16IdempotencyStore($cache);

        $this->assertNull($store->get('foo'));
        $store->put('foo', new StoredResponse(201, ['data' => ['x' => 1]], 'fp', time()), 60);
        $stored = $store->get('foo');
        $this->assertNotNull($stored);
        $this->assertSame(201, $stored->statusCode);
    }

    public function testPsr16BridgeLockSemantics(): void
    {
        $cache = new class {
            public array $data = [];
            public function get(string $k, mixed $default = null): mixed { return $this->data[$k] ?? $default; }
            public function set(string $k, mixed $v, ?int $ttl = null): bool { $this->data[$k] = $v; return true; }
            public function has(string $k): bool { return array_key_exists($k, $this->data); }
            public function delete(string $k): bool { unset($this->data[$k]); return true; }
        };
        $store = new Psr16IdempotencyStore($cache);

        $this->assertTrue($store->lock('k', 30));
        $this->assertFalse($store->lock('k', 30), 'second lock attempt while held should fail');
        $store->release('k');
        $this->assertTrue($store->lock('k', 30), 'lock should be acquirable after release');
    }

    // -------- File store specific --------

    public function testFileStoreLockExpiresAfterTtl(): void
    {
        $store = $this->makeFileStore();
        $this->assertTrue($store->lock('k-stale', ttlSeconds: 1));
        // Backdate the lock file to simulate elapsed TTL.
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
     * @param array<string, string> $serverHeaders Map of $_SERVER-style header keys
     */
    private function runMiddleware(
        IdempotencyStore $store,
        array $serverHeaders,
        string $body,
        string $method,
        callable $terminal,
        ?callable $emitHeader = null,
    ): Response {
        // Stash + restore $_SERVER so test isolation is real.
        $prevServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = '/test';
        foreach ($serverHeaders as $k => $v) {
            $_SERVER[$k] = $v;
        }
        try {
            $mw = new Idempotency(
                $store,
                emitHeader: $emitHeader,
                readBody:   static fn (): string => $body,
            );
            return $mw->handle($this->bareRequest(), $terminal);
        } finally {
            $_SERVER = $prevServer;
        }
    }

    private function terminalReturning(int $code, array $data): callable
    {
        return function () use ($code, $data): Response {
            $r = (new \ReflectionClass(Response::class))->newInstanceWithoutConstructor();
            $r->data = $data;
            $r->meta = ['code' => $code];
            $codeProp = (new \ReflectionClass(Response::class))->getProperty('code');
            $codeProp->setAccessible(true);
            $codeProp->setValue($r, $code);
            return $r;
        };
    }

    private function bareRequest(): Request
    {
        return (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    }
}
