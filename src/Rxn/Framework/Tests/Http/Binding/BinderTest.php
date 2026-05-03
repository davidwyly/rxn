<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Codegen\DumpCache;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Http\Middleware\JsonBody;
use Rxn\Framework\Tests\Http\Binding\Fixture\Address;
use Rxn\Framework\Tests\Http\Binding\Fixture\CreateProduct;

final class BinderTest extends TestCase
{
    public function testHydratesTypedProperties(): void
    {
        $dto = Binder::bind(CreateProduct::class, [
            'name'     => 'Widget',
            'price'    => '1299',       // string → int cast
            'slug'     => 'widget-v2',
            'status'   => 'published',
            'featured' => 'true',       // string → bool cast
        ]);

        $this->assertSame('Widget', $dto->name);
        $this->assertSame(1299, $dto->price);
        $this->assertSame('widget-v2', $dto->slug);
        $this->assertSame('published', $dto->status);
        $this->assertTrue($dto->featured);
    }

    public function testUsesDefaultsWhenOmitted(): void
    {
        $dto = Binder::bind(CreateProduct::class, [
            'name'  => 'Widget',
            'price' => 10,
        ]);
        $this->assertSame('default-slug', $dto->slug);
        $this->assertSame('draft', $dto->status);
        $this->assertFalse($dto->featured);
        $this->assertNull($dto->note);
    }

    public function testRequiredFieldsFailWith422(): void
    {
        try {
            Binder::bind(CreateProduct::class, []);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $fields = array_column($e->errors(), 'field');
            $this->assertContains('name', $fields);
            $this->assertContains('price', $fields);
        }
    }

    public function testTypeMismatchIsReported(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'name'  => 'Widget',
                'price' => 'not-a-number',
            ]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertContains(
                ['field' => 'price', 'message' => 'type mismatch'],
                $errors
            );
        }
    }

    public function testMinBoundFails(): void
    {
        try {
            Binder::bind(CreateProduct::class, ['name' => 'W', 'price' => -5]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame([['field' => 'price', 'message' => 'must be >= 0']], $e->errors());
        }
    }

    public function testMaxBoundFails(): void
    {
        try {
            Binder::bind(CreateProduct::class, ['name' => 'W', 'price' => 10_000_000]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame([['field' => 'price', 'message' => 'must be <= 1000000']], $e->errors());
        }
    }

    public function testLengthAboveMaxFails(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'name'  => str_repeat('a', 101),
                'price' => 1,
            ]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame([['field' => 'name', 'message' => 'must be at most 100 characters']], $e->errors());
        }
    }

    public function testPatternFails(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'name'  => 'W',
                'price' => 1,
                'slug'  => 'NOT LOWERCASE',
            ]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame(
                [['field' => 'slug', 'message' => 'does not match required pattern']],
                $e->errors()
            );
        }
    }

    public function testInSetRejectsUnknown(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'name'   => 'W',
                'price'  => 1,
                'status' => 'pending',
            ]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame(
                [['field' => 'status', 'message' => "must be one of: 'draft', 'published', 'archived'"]],
                $e->errors()
            );
        }
    }

    public function testCollectsEveryErrorAtOnce(): void
    {
        try {
            Binder::bind(CreateProduct::class, [
                'price' => -1,
                'slug'  => 'BAD SLUG',
                'status' => 'pending',
            ]);
            $this->fail();
        } catch (ValidationException $e) {
            $fields = array_column($e->errors(), 'field');
            sort($fields);
            // name (missing), price (<0), slug (bad pattern), status (not in set)
            $this->assertSame(['name', 'price', 'slug', 'status'], $fields);
        }
    }

    public function testFailsOnNonDtoClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional misuse */
        Binder::bind(\stdClass::class, []);
    }

    public function testBoolCastAcceptsCommonTruthyStrings(): void
    {
        foreach (['true', '1', 'yes', 'on'] as $truthy) {
            $dto = Binder::bind(CreateProduct::class, [
                'name'     => 'W',
                'price'    => 1,
                'featured' => $truthy,
            ]);
            $this->assertTrue($dto->featured, "'$truthy' should cast to true");
        }
        foreach (['false', '0', 'no', 'off'] as $falsy) {
            $dto = Binder::bind(CreateProduct::class, [
                'name'     => 'W',
                'price'    => 1,
                'featured' => $falsy,
            ]);
            $this->assertFalse($dto->featured, "'$falsy' should cast to false");
        }
    }

    public function testReadsFromMergedSuperglobalsByDefault(): void
    {
        $prevGet  = $_GET;
        $prevPost = $_POST;
        try {
            $_GET  = ['name' => 'fromQuery'];
            $_POST = ['price' => 99];   // POST overrides GET on conflicts
            $dto   = Binder::bind(CreateProduct::class);
            $this->assertSame('fromQuery', $dto->name);
            $this->assertSame(99, $dto->price);
        } finally {
            $_GET  = $prevGet;
            $_POST = $prevPost;
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function compileParityCases(): iterable
    {
        yield 'all valid' => [[
            'name'     => 'Widget',
            'price'    => '1299',
            'slug'     => 'widget-v2',
            'status'   => 'published',
            'featured' => 'true',
            'note'     => 'a note',
        ]];

        yield 'optional fields default' => [[
            'name'  => 'Widget',
            'price' => 100,
        ]];

        yield 'all invalid' => [[
            'name'     => '',                   // empty -> required fires
            'price'    => 'free',                // not numeric
            'slug'     => 'BAD slug',            // pattern fail
            'status'   => 'unknown',             // not in set
            'featured' => 'maybe',               // bool cast fail
        ]];

        yield 'mins / maxes / lengths' => [[
            'name'   => str_repeat('A', 200),    // length max:100 fails
            'price'  => -1,                      // min:0 fails
            'slug'   => 'ok-slug',
            'status' => 'draft',
        ]];

        yield 'edge: nullable note explicit null' => [[
            'name'  => 'X',
            'price' => 1,
            'note'  => null,
        ]];
    }

    /**
     * Compiled binder must produce identical hydrated state and identical
     * error sets to the runtime path. Both forms catch the exception and
     * compare the collected errors when validation fails.
     *
     * @dataProvider compileParityCases
     * @param array<string, mixed> $bag
     */
    public function testCompiledOutputMatchesRuntimeOutput(array $bag): void
    {
        $runtimeError = null;
        $compiledError = null;
        $runtimeDto = null;
        $compiledDto = null;
        try {
            $runtimeDto = Binder::bind(CreateProduct::class, $bag);
        } catch (ValidationException $e) {
            $runtimeError = $e->errors();
        }
        $bind = Binder::compileFor(CreateProduct::class);
        try {
            $compiledDto = $bind($bag);
        } catch (ValidationException $e) {
            $compiledError = $e->errors();
        }
        if ($runtimeError !== null || $compiledError !== null) {
            $this->assertSame($runtimeError, $compiledError, 'error sets diverged');
            return;
        }
        $this->assertEquals($runtimeDto, $compiledDto, 'hydrated state diverged');
    }

    public function testCompileForCachesPerClass(): void
    {
        $a = Binder::compileFor(CreateProduct::class);
        $b = Binder::compileFor(CreateProduct::class);
        $this->assertSame($a, $b);
    }

    public function testBindRequestUsesParsedBodyWhenSet(): void
    {
        // JsonBody middleware (or any PSR-15 body parser) sets
        // parsedBody on the request. bindRequest must read from
        // there — no globals in play.
        $request = (new \Nyholm\Psr7\ServerRequest('POST', 'http://test.local/?foo=bar'))
            ->withQueryParams(['foo' => 'bar'])
            ->withParsedBody(['name' => 'widget', 'price' => 999]);

        $dto = Binder::bindRequest(CreateProduct::class, $request);
        $this->assertSame('widget', $dto->name);
        $this->assertSame(999, $dto->price);
    }

    public function testBindRequestDecodesJsonBodyInlineWhenNoMiddleware(): void
    {
        // No JsonBody middleware ran → parsedBody is null.
        // bindRequest must still bind by decoding the raw body
        // when Content-Type says application/json. Closes the
        // implicit dependency on JsonBody having run.
        $body = json_encode(['name' => 'inline', 'price' => 500]);
        $request = new \Nyholm\Psr7\ServerRequest(
            'POST',
            'http://test.local/',
            ['Content-Type' => 'application/json'],
            $body,
        );

        $dto = Binder::bindRequest(CreateProduct::class, $request);
        $this->assertSame('inline', $dto->name);
        $this->assertSame(500, $dto->price);
    }

    public function testBindRequestQueryParamsAreOverriddenByBody(): void
    {
        // Match gatherBag's existing precedence (GET → POST, body wins).
        $request = (new \Nyholm\Psr7\ServerRequest('POST', 'http://test.local/?name=fromQuery'))
            ->withQueryParams(['name' => 'fromQuery'])
            ->withParsedBody(['name' => 'fromBody', 'price' => 1]);

        $dto = Binder::bindRequest(CreateProduct::class, $request);
        $this->assertSame('fromBody', $dto->name);
    }

    public function testGatherFromRequestEmptyBodyReturnsQueryOnly(): void
    {
        $request = (new \Nyholm\Psr7\ServerRequest('GET', 'http://test.local/?a=1&b=two'))
            ->withQueryParams(['a' => '1', 'b' => 'two']);

        $bag = Binder::gatherFromRequest($request);
        $this->assertSame(['a' => '1', 'b' => 'two'], $bag);
    }

    public function testGatherFromRequestIgnoresNonJsonBody(): void
    {
        // Form-encoded body without parsedBody set → not the
        // binder's problem. Returns just the query bag.
        $request = (new \Nyholm\Psr7\ServerRequest(
            'POST',
            'http://test.local/?q=1',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'name=ada',
        ))->withQueryParams(['q' => '1']);

        $bag = Binder::gatherFromRequest($request);
        $this->assertSame(['q' => '1'], $bag);
    }

    public function testGatherFromRequestSkipsOversizedJsonFromDeclaredLength(): void
    {
        $body = json_encode(['name' => 'too-big']);
        $this->assertNotFalse($body, 'fixture json_encode must succeed');
        $request = (new \Nyholm\Psr7\ServerRequest(
            'POST',
            'http://test.local/?q=1',
            ['Content-Type' => 'application/json', 'Content-Length' => (string)(JsonBody::DEFAULT_MAX_BYTES + 1)],
            $body,
        ))->withQueryParams(['q' => '1']);

        $bag = Binder::gatherFromRequest($request);
        $this->assertSame(['q' => '1'], $bag);
    }

    public function testGatherFromRequestSkipsOversizedJsonWithoutDeclaredLength(): void
    {
        $maxJsonBytes = JsonBody::DEFAULT_MAX_BYTES;
        $payload      = json_encode(['blob' => str_repeat('a', $maxJsonBytes + 1)]);
        $this->assertNotFalse($payload);
        $request = (new \Nyholm\Psr7\ServerRequest(
            'POST',
            'http://test.local/?q=1',
            ['Content-Type' => 'application/json'],
            $payload,
        ))->withQueryParams(['q' => '1']);

        $bag = Binder::gatherFromRequest($request);
        $this->assertSame(['q' => '1'], $bag);
    }

    public function testGatherFromRequestSkipsOversizedJsonFromUnknownSizeStream(): void
    {
        $maxJsonBytes = JsonBody::DEFAULT_MAX_BYTES;
        $payload      = json_encode(['blob' => str_repeat('a', $maxJsonBytes + 1)]);
        $this->assertNotFalse($payload);

        // Stream returns null from getSize() — exercises the loop-read
        // cap directly. Without the cap, `(string)$body` would buffer
        // the full payload before the post-read length check fires.
        $stream  = new \Rxn\Framework\Tests\Http\Binding\Fixture\UnknownSizeStream($payload);
        $request = (new \Nyholm\Psr7\ServerRequest(
            'POST',
            'http://test.local/?q=1',
            ['Content-Type' => 'application/json'],
            $stream,
        ))->withQueryParams(['q' => '1']);

        $bag = Binder::gatherFromRequest($request);
        $this->assertSame(['q' => '1'], $bag);
    }

    // -------- dump path (Tier B) --------

    private string $dumpDir = '';

    protected function setUp(): void
    {
        $this->dumpDir = sys_get_temp_dir() . '/rxn-binder-dump-' . bin2hex(random_bytes(4));
        @mkdir($this->dumpDir, 0770, true);
    }

    protected function tearDown(): void
    {
        DumpCache::useDir(null);
        Binder::clearCache();
        if ($this->dumpDir !== '' && is_dir($this->dumpDir)) {
            foreach (glob($this->dumpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dumpDir);
        }
    }

    /**
     * Note: the dump path applies to `Binder::compileFor()` — the
     * eval-compiled fast path. The default `Binder::bind()` is a
     * runtime Reflection walker that never goes through eval, so
     * tier-B's dump cache doesn't touch it. Apps that need the
     * preload story call `compileFor()` and use the closure
     * directly.
     */
    public function testCompiledBinderIsDumpedToFileWhenCacheDirSet(): void
    {
        DumpCache::useDir($this->dumpDir);
        Binder::clearCache();

        $bind = Binder::compileFor(CreateProduct::class);
        $dto  = $bind(['name' => 'Widget', 'price' => '99']);
        $this->assertSame('Widget', $dto->name);
        $this->assertSame(99, $dto->price);

        $files = glob($this->dumpDir . '/*.php') ?: [];
        $this->assertNotEmpty($files, 'compiled binder should have dumped to disk');
        $contents = file_get_contents($files[0]);
        $this->assertStringStartsWith("<?php\n", $contents);
        // The dumped file always opens with a `$validators = [...]`
        // prelude so the closure's `use ($validators)` capture
        // resolves at require time.
        $this->assertStringContainsString('$validators = [', $contents);
        $this->assertStringContainsString('CreateProduct', $contents);
    }

    public function testDumpedBinderForDtoWithSideTableValidatorReconstructsValidators(): void
    {
        DumpCache::useDir($this->dumpDir);
        Binder::clearCache();

        // Cold-compile via the dump path. CountryCode is non-
        // inlinable, so it goes through the side-table branch —
        // the dumped file has to reconstruct it as
        // `new \...\CountryCode(allowed: [...], message: '...')`.
        $bind = Binder::compileFor(Address::class);
        $dto  = $bind(['line1' => '123 Main St', 'country' => 'CA']);
        $this->assertSame('CA', $dto->country);

        $files = glob($this->dumpDir . '/*.php') ?: [];
        $this->assertNotEmpty($files);
        $contents = file_get_contents($files[0]);
        // Pin the exact expression shape — named args, escaped FQCN,
        // var_export'd literals.
        $this->assertStringContainsString(
            "new \\Rxn\\Framework\\Tests\\Http\\Binding\\Fixture\\CountryCode(allowed: array",
            $contents,
        );
        $this->assertStringContainsString(
            "message: 'must be a North American country'",
            $contents,
        );
    }

    public function testDumpedBinderRejectsBadCountryCode(): void
    {
        DumpCache::useDir($this->dumpDir);
        Binder::clearCache();

        $bind = Binder::compileFor(Address::class);
        $this->expectException(ValidationException::class);
        $bind(['line1' => '123 Main St', 'country' => 'FR']);  // not in allow-list
    }

    public function testDumpedBinderReusesExistingFileOnSecondLoad(): void
    {
        DumpCache::useDir($this->dumpDir);
        Binder::clearCache();

        $bind1 = Binder::compileFor(CreateProduct::class);
        $bind1(['name' => 'A', 'price' => '1']);

        $files1 = glob($this->dumpDir . '/*.php') ?: [];
        $this->assertCount(1, $files1);
        $mtime1 = filemtime($files1[0]);

        // Wipe in-memory compiled cache only — file on disk stays.
        $ref = new \ReflectionClass(Binder::class);
        $cacheProp = $ref->getProperty('compiledCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue(null, []);

        clearstatcache();
        $bind2 = Binder::compileFor(CreateProduct::class);
        $bind2(['name' => 'C', 'price' => '3']);

        clearstatcache();
        $files2 = glob($this->dumpDir . '/*.php') ?: [];
        $this->assertSame($files1, $files2, 'no new file should appear on second load');
        $this->assertSame($mtime1, filemtime($files2[0]));
    }

    public function testCompileForFallsBackToEvalWhenDumpCacheNotSet(): void
    {
        DumpCache::useDir(null);
        Binder::clearCache();

        $bind = Binder::compileFor(CreateProduct::class);
        $dto  = $bind(['name' => 'NoFile', 'price' => '1']);
        $this->assertSame('NoFile', $dto->name);
        $this->assertEmpty(
            glob($this->dumpDir . '/*.php') ?: [],
            'no files should land in the dump dir when DumpCache is not configured',
        );
    }
}
