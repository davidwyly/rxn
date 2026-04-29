<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Idempotency;

/**
 * Snapshot of a Response that's been stored against an
 * idempotency key. Holds enough state to reconstruct the response
 * verbatim on replay, plus the fingerprint of the original request
 * body — used to detect "same key, different body" client bugs.
 *
 * @internal Owned by Idempotency middleware + stores; not part of
 *           the public API surface.
 */
final class StoredResponse
{
    /**
     * @param array<string, mixed> $body  The stripped Response param map
     *                                    (data / errors / meta etc.).
     */
    public function __construct(
        public readonly int    $statusCode,
        public readonly array  $body,
        public readonly string $fingerprint,
        public readonly int    $createdAt,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>, fingerprint: string, created_at: int}
     */
    public function toArray(): array
    {
        return [
            'status'      => $this->statusCode,
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
        return new self(
            statusCode:  (int)($raw['status'] ?? 200),
            body:        is_array($raw['body'] ?? null) ? $raw['body'] : [],
            fingerprint: (string)($raw['fingerprint'] ?? ''),
            createdAt:   (int)($raw['created_at'] ?? 0),
        );
    }
}
