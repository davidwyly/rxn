<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Middleware;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Event\EventDispatcher;
use Rxn\Framework\Event\ListenerProvider;
use Rxn\Framework\Http\Idempotency\Event\IdempotencyHit;
use Rxn\Framework\Http\Idempotency\Event\IdempotencyMiss;
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

        $files = glob($this->tmpDir . '/*.json') ?: [];
        $this->assertCount(1, $files);
        $envelope = (array) json_decode((string) file_get_contents($files[0]), true);
        $stored = StoredResponse::fromArray((array) ($envelope['data'] ?? []));
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

    public function testReplayDoesNotReturnSensitiveHeaders(): void
    {
        $store = $this->makeFileStore();

        $terminal = function (ServerRequestInterface $request): ResponseInterface {
            return new Psr7Response(
                201,
                [
                    'Content-Type' => 'application/json',
                    'Set-Cookie'   => 'session=victim',
                    'X-Token'      => 'victim-token',
                ],
                '{"data":{"id":7}}',
            );
        };

        $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-sensitive'],
            body: '{"a":1}',
            terminal: $terminal,
        );
        $replayed = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-sensitive'],
            body: '{"a":1}',
            terminal: $this->jsonTerminal(201, ['id' => 8]),
        );

        $this->assertSame('application/json', $replayed->getHeaderLine('Content-Type'));
        $this->assertSame('', $replayed->getHeaderLine('Set-Cookie'));
        $this->assertSame('', $replayed->getHeaderLine('X-Token'));
    }


    public function testReplayAllowlistIsCaseInsensitive(): void
    {
        $store = $this->makeFileStore();

        // Terminal response uses lowercase `content-type`; the allowlist
        // entry is the title-cased `Content-Type`. The middleware must
        // still replicate the header because PSR-7 names are case-insensitive.
        $terminal = function (ServerRequestInterface $request): ResponseInterface {
            return new Psr7Response(
                201,
                [
                    'content-type' => 'application/json',
                    'Set-Cookie'   => 'session=victim',
                ],
                '{"data":{"id":9}}',
            );
        };

        $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-case'],
            body: '{"a":1}',
            terminal: $terminal,
        );
        $replayed = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-case'],
            body: '{"a":1}',
            terminal: $this->jsonTerminal(500, []),
        );

        $this->assertSame('application/json', $replayed->getHeaderLine('Content-Type'),
            'allowlisted header must be replayed regardless of original casing');
        $this->assertSame('', $replayed->getHeaderLine('Set-Cookie'),
            'non-allowlisted header must not be replayed');
    }

    public function testSameKeyDoesNotReplayAcrossAuthorizationScopes(): void
    {
        $store = $this->makeFileStore();

        $alice = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-scope', 'Authorization' => 'Bearer alice'],
            body: '{"a":1}',
            terminal: $this->jsonTerminal(201, ['id' => 'alice']),
        );
        $aliceData = json_decode((string)$alice->getBody(), true);
        $this->assertSame(['id' => 'alice'], $aliceData['data']);

        $count = 0;
        $bob = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-scope', 'Authorization' => 'Bearer bob'],
            body: '{"a":1}',
            terminal: function () use (&$count): ResponseInterface {
                $count++;
                return $this->jsonResponse(201, ['id' => 'bob']);
            },
        );

        $this->assertSame(1, $count, 'request with different auth scope should not replay cached response');
        $bobData = json_decode((string)$bob->getBody(), true);
        $this->assertSame(['id' => 'bob'], $bobData['data']);
    }

    public function testOversizedBodyReturns413(): void
    {
        $store = $this->makeFileStore();
        $response = $this->runMiddleware(
            $store,
            method: 'POST',
            headers: ['Idempotency-Key' => 'k-big'],
            body: str_repeat('a', 20),
            terminal: $this->jsonTerminal(201, ['ok' => true]),
            maxBodyBytes: 10,
        );
        $this->assertSame(413, $response->getStatusCode());
        $problem = json_decode((string)$response->getBody(), true);
        $this->assertSame('idempotency_request_body_too_large', $problem['type']);
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
        $scopedKey = hash('sha256', "\n" . 'k-conflict');
        $this->assertTrue($store->lock($scopedKey, 30));

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

        $store->release($scopedKey);
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
        $this->assertCount(0, glob($this->tmpDir . '/*.json') ?: [],
            '5xx responses should not be cached so retries can hit a healthy backend');
    }

    public function testColdPathEmitsMissEventWhenDispatcherProvided(): void
    {
        $store    = $this->makeFileStore();
        $captured = [];
        $provider = new ListenerProvider();
        $provider->listen(IdempotencyMiss::class, function ($e) use (&$captured): void { $captured[] = $e; });

        $this->runMiddleware(
            $store,
            method:   'POST',
            headers:  ['Idempotency-Key' => 'k-miss'],
            body:     '{"a":1}',
            terminal: $this->jsonTerminal(201, ['ok' => true]),
            events:   new EventDispatcher($provider),
        );

        $this->assertCount(1, $captured);
        $this->assertInstanceOf(IdempotencyMiss::class, $captured[0]);
        $this->assertSame('k-miss', $captured[0]->key);
    }

    public function testReplayPathEmitsHitEventWhenDispatcherProvided(): void
    {
        $store    = $this->makeFileStore();
        $captured = [];
        $provider = new ListenerProvider();
        $provider->listen(IdempotencyHit::class, function ($e) use (&$captured): void { $captured[] = $e; });
        $events   = new EventDispatcher($provider);

        // Cold call seeds the store. Pass dispatcher so the miss
        // event fires too — this test only listens for hits, so
        // the miss is silently ignored by the listener provider.
        $this->runMiddleware(
            $store,
            method:   'POST',
            headers:  ['Idempotency-Key' => 'k-hit'],
            body:     '{"a":1}',
            terminal: $this->jsonTerminal(201, ['id' => 7]),
            events:   $events,
        );

        // Replay — should fire IdempotencyHit.
        $this->runMiddleware(
            $store,
            method:   'POST',
            headers:  ['Idempotency-Key' => 'k-hit'],
            body:     '{"a":1}',
            terminal: $this->jsonTerminal(500, ['this' => 'must not run']),
            events:   $events,
        );

        $this->assertCount(1, $captured, 'exactly one hit event for the replay');
        $this->assertSame('k-hit', $captured[0]->key);
        $this->assertSame(201, $captured[0]->replayedStatus);
    }

    public function testNoDispatcherIsSilent(): void
    {
        // Belt-and-braces: when no dispatcher is wired, the
        // middleware must work end-to-end without trying to call
        // anything event-shaped. (The conditional null-safe call
        // guarantees this; the test pins it down.)
        $store    = $this->makeFileStore();
        $response = $this->runMiddleware(
            $store,
            method:   'POST',
            headers:  ['Idempotency-Key' => 'k-noevents'],
            body:     '{"a":1}',
            terminal: $this->jsonTerminal(201, ['ok' => true]),
        );
        $this->assertSame(201, $response->getStatusCode());
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
        ?EventDispatcherInterface $events = null,
        int $maxBodyBytes = 1_048_576,
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
        return (new Idempotency(
            store: $store,
            maxBodyBytes: $maxBodyBytes,
            events: $events,
        ))->process($request, $handler);
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
