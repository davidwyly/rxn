<?php declare(strict_types=1);

namespace Rxn\Framework\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * Fluent wrapper around a PSR-7 ResponseInterface with assertion
 * helpers that integrate with PHPUnit's failure machinery. Each
 * assert* returns $this so tests read as a single chained
 * expression.
 *
 *   $client->get('/products/42')
 *          ->assertOk()
 *          ->assertJsonPath('data.email', 'ada@example.com')
 *          ->assertJsonStructure(['data' => ['id', 'email']]);
 *
 * Wraps — doesn't replace — the underlying ResponseInterface, which
 * is available via `response()` for escape-hatch assertions.
 */
final class TestResponse
{
    public function __construct(private ResponseInterface $response) {}

    public function response(): ResponseInterface
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /** @return mixed */
    public function data()
    {
        $json = $this->json();
        return $json['data'] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $body = (string)$this->response->getBody();
        if ($this->response->getBody()->isSeekable()) {
            $this->response->getBody()->rewind();
        }
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status(), "Expected status $expected, got {$this->status()}");
        return $this;
    }

    public function assertOk(): self              { return $this->assertStatus(200); }
    public function assertCreated(): self         { return $this->assertStatus(201); }
    public function assertNoContent(): self       { return $this->assertStatus(204); }
    public function assertNotModified(): self     { return $this->assertStatus(304); }
    public function assertBadRequest(): self      { return $this->assertStatus(400); }
    public function assertUnauthorized(): self    { return $this->assertStatus(401); }
    public function assertForbidden(): self       { return $this->assertStatus(403); }
    public function assertNotFound(): self        { return $this->assertStatus(404); }
    public function assertMethodNotAllowed(): self{ return $this->assertStatus(405); }
    public function assertServerError(): self     { return $this->assertStatus(500); }

    /**
     * Assert a dotted path through the response body equals $expected.
     * Works for both the native `{data, meta}` envelope and the
     * Problem Details error shape.
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        $actual = $this->extractPath($this->json(), $path);
        Assert::assertSame($expected, $actual, "JSON path '$path' mismatch");
        return $this;
    }

    /**
     * Shallow structural check — every key in $expected must appear
     * in the response body, recursively. Values are ignored; only
     * shape is asserted.
     *
     * @param array<mixed> $expected
     */
    public function assertJsonStructure(array $expected): self
    {
        $this->assertStructure($expected, $this->json(), '');
        return $this;
    }

    /**
     * Assert that the response carries a header with the given
     * value (case-insensitive header name match).
     */
    public function assertHeader(string $name, string $expected): self
    {
        $actual = $this->response->getHeaderLine($name);
        Assert::assertSame($expected, $actual, "Header '$name' mismatch");
        return $this;
    }

    /**
     * @param array<mixed> $structure
     * @param array<mixed> $actual
     */
    private function assertStructure(array $structure, array $actual, string $prefix): void
    {
        foreach ($structure as $key => $value) {
            if (is_int($key)) {
                Assert::assertIsArray($actual, "Expected array at '$prefix'");
                // Numeric key means "every element should match".
                if (is_array($value)) {
                    foreach ($actual as $i => $item) {
                        $this->assertStructure($value, is_array($item) ? $item : [], "{$prefix}[$i]");
                    }
                }
                continue;
            }
            Assert::assertArrayHasKey($key, $actual, "Missing key '{$prefix}{$key}'");
            if (is_array($value)) {
                $this->assertStructure($value, is_array($actual[$key]) ? $actual[$key] : [], "{$prefix}{$key}.");
            }
        }
    }

    /**
     * @param array<string, mixed> $bag
     * @return mixed
     */
    private function extractPath(array $bag, string $path)
    {
        $cursor = $bag;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }
}
