<?php declare(strict_types=1);

/**
 * PSR-16 `CacheInterface` stub — defined ONLY when the real
 * `psr/simple-cache` package isn't installed.
 *
 * The framework declares `psr/simple-cache` as a `suggest` rather
 * than `require`: apps that don't use `Psr16IdempotencyStore`
 * never see PSR-16. But `Psr16IdempotencyStore::__construct` has a
 * nominal `\Psr\SimpleCache\CacheInterface` type-hint, and the
 * tests for that bridge need *some* CacheInterface to exist in
 * order to construct test doubles.
 *
 * In real environments (CI, production, anyone running
 * `composer require psr/simple-cache`), the real interface is
 * already loaded and this file is a no-op. In sandbox / offline
 * environments, this stub fills in.
 *
 * The signatures match `psr/simple-cache:^3.0` exactly.
 */

if (!interface_exists(\Psr\SimpleCache\CacheInterface::class)) {
    // Pragma: load the stub interface inside its own namespace.
    // No PSR-4 entry needed — this file is required explicitly by
    // the test bootstrap.
    eval(<<<'PHP'
        namespace Psr\SimpleCache;

        interface CacheInterface
        {
            public function get(string $key, mixed $default = null): mixed;
            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;
            public function delete(string $key): bool;
            public function clear(): bool;
            public function getMultiple(iterable $keys, mixed $default = null): iterable;
            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;
            public function deleteMultiple(iterable $keys): bool;
            public function has(string $key): bool;
        }

        interface CacheException extends \Throwable {}
        interface InvalidArgumentException extends CacheException {}
        PHP);
}
