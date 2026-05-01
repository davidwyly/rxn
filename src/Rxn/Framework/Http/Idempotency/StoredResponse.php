<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Idempotency;

/**
 * Snapshot of a PSR-7 ResponseInterface that's been stored against
 * an idempotency key. Holds enough state to reconstruct the
 * response verbatim on replay (status, headers, body bytes), plus
 * the fingerprint of the original request — used to detect "same
 * key, different body" client bugs.
 *
 * @internal Owned by Idempotency middleware + stores; not part of
 *           the public API surface.
 */
final class StoredResponse
{
    /**
     * @param array<string, list<string>> $headers PSR-7-shaped
     *                                             headers (lower-cased
     *                                             name → list of values).
     */
    public function __construct(
        public readonly int    $statusCode,
        public readonly array  $headers,
        public readonly string $body,
        public readonly string $fingerprint,
        public readonly int    $createdAt,
    ) {}

    /**
     * @return array{status: int, headers: array<string, list<string>>, body: string, fingerprint: string, created_at: int}
     */
    public function toArray(): array
    {
        return [
            'status'      => $this->statusCode,
            'headers'     => $this->headers,
            'body'        => $this->body,
            'fingerprint' => $this->fingerprint,
            'created_at'  => $this->createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $headers = $raw['headers'] ?? [];
        return new self(
            statusCode:  (int)($raw['status'] ?? 200),
            headers:     is_array($headers) ? $headers : [],
            body:        (string)($raw['body'] ?? ''),
            fingerprint: (string)($raw['fingerprint'] ?? ''),
            createdAt:   (int)($raw['created_at'] ?? 0),
        );
    }
}
