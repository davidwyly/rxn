<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 stream that always reports `null` from `getSize()` and is
 * non-seekable. Models the case where a request body lands as a
 * pipe / forwarded stream whose total length is unknown until
 * EOF.
 *
 * Returns chunks from `$payload` on each `read()` call so the
 * loop-read path in `Binder::gatherFromRequest` and
 * `JsonBody::process` actually exercises its cap-and-stop logic
 * — `(string)$body` would otherwise still return the full payload.
 */
final class UnknownSizeStream implements StreamInterface
{
    private int $offset = 0;

    public function __construct(private readonly string $payload) {}

    public function __toString(): string
    {
        $rest = $this->getContents();
        $this->offset = strlen($this->payload);
        return $rest;
    }

    public function close(): void {}
    public function detach() { return null; }
    public function getSize(): ?int { return null; }
    public function tell(): int { return $this->offset; }
    public function eof(): bool { return $this->offset >= strlen($this->payload); }
    public function isSeekable(): bool { return false; }
    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new \RuntimeException('not seekable');
    }
    public function rewind(): void { throw new \RuntimeException('not seekable'); }
    public function isWritable(): bool { return false; }
    public function write($string): int { throw new \RuntimeException('not writable'); }
    public function isReadable(): bool { return true; }
    public function read($length): string
    {
        $remaining = strlen($this->payload) - $this->offset;
        $take      = max(0, min($length, $remaining));
        $chunk     = substr($this->payload, $this->offset, $take);
        $this->offset += strlen($chunk);
        return $chunk;
    }
    public function getContents(): string
    {
        $rest = substr($this->payload, $this->offset);
        $this->offset = strlen($this->payload);
        return $rest;
    }
    public function getMetadata($key = null) { return $key === null ? [] : null; }
}
