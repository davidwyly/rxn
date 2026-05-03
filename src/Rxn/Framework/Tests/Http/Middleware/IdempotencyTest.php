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
        $this->assertCount(0, glob($this->tmpDir . '/*.json') ?: [], 'GET should not have been recorded');
    }

    public function testColdKeyStoresResponse(): void
    {
        $store = $this->makeFileStore();
        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'k-cold'];
        $response = $this->runMiddleware($store, $headers, body: '{"a":1}', method: 'POST', terminal: $this->terminalReturning(201, ['id' => 42]));
        $this->assertSame(201, $response->getCode());

        $files = glob($this->tmpDir . '/*.json') ?: [];
        $this->assertCount(1, $files);
        $envelope = (array) json_decode((string) file_get_contents($files[0]), true);
        $stored = StoredResponse::fromArray((array) ($envelope['data'] ?? []));
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


    public function testSameKeyDoesNotReplayAcrossAuthorizationScopes(): void
    {
        $store = $this->makeFileStore();
        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'k-scope', 'HTTP_AUTHORIZATION' => 'Bearer alice'];

        $alice = $this->runMiddleware($store, $headers, body: '{"a":1}', method: 'POST', terminal: $this->terminalReturning(201, ['id' => 'alice']));
        $this->assertSame(['id' => 'alice'], $alice->data);

        $count = 0;
        $bob = $this->runMiddleware(
            $store,
            ['HTTP_IDEMPOTENCY_KEY' => 'k-scope', 'HTTP_AUTHORIZATION' => 'Bearer bob'],
            body: '{"a":1}',
            method: 'POST',
            terminal: function () use (&$count): Response {
                $count++;
                return $this->terminalReturning(201, ['id' => 'bob'])();
            },
        );

        $this->assertSame(1, $count, 'request with different auth scope should not replay cached response');
        $this->assertSame(['id' => 'bob'], $bob->data);
    }

    public function testOversizedBodyReturns413(): void
    {
        $store = $this->makeFileStore();
        $response = $this->runMiddleware(
            $store,
            ['HTTP_IDEMPOTENCY_KEY' => 'k-big'],
            body: str_repeat('a', 20),
            method: 'POST',
            terminal: $this->terminalReturning(201, ['ok' => true]),
            maxBodyBytes: 10,
        );
        $this->assertSame(413, $response->getCode());
        $this->assertSame('idempotency_request_body_too_large', $response->meta['type']);
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
        $scopedKey = hash('sha256', "\n" . 'k-conflict');
        $this->assertTrue($store->lock($scopedKey, 30));

        $response = $this->runMiddleware($store, $headers, body: '{"a":1}', method: 'POST', terminal: $this->terminalReturning(201, ['ok' => true]));
        $this->assertSame(409, $response->getCode());
        $this->assertSame('idempotency_key_in_use', $response->meta['type']);

        $store->release($scopedKey);
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
        $this->assertCount(0, glob($this->tmpDir . '/*.json') ?: [],
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
    //
    // The bridge declares a nominal `\Psr\SimpleCache\CacheInterface`
    // type-hint. PSR-16 isn't a hard dependency of the framework
    // (it's `suggest`-only), so these tests load a stub interface
    // when the real package isn't installed — see
    // `tests/Fixture/Psr16Stub.php`.

    protected function loadPsr16Stub(): void
    {
        require_once __DIR__ . '/../../Fixture/Psr16Stub.php';
    }

    public function testPsr16BridgeRejectsNonCacheInterface(): void
    {
        $this->loadPsr16Stub();
        // PHP's nominal type system enforces the interface — nothing
        // for `Psr16IdempotencyStore` itself to validate.
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
        $store->put('foo', new StoredResponse(201, ['data' => ['x' => 1]], 'fp', time()), 60);
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

    /**
     * In-memory PSR-16 implementation for the bridge tests. Built
     * via eval inside the test to keep the `implements` clause off
     * the file's parse path — that way running this test file
     * doesn't require the PSR-16 stub to be loaded yet.
     */
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
        int $maxBodyBytes = 1_048_576,
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
                maxBodyBytes: $maxBodyBytes,
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
