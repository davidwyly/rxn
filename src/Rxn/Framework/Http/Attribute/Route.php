<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

/**
 * Declare a router entry directly on a controller method:
 *
 *   #[Route('GET', '/products/{id:int}', name: 'products.show')]
 *   public function show(int $id): array { ... }
 *
 * The Scanner reflects these into actual `Router::add()` calls, so
 * controllers stay the single source of truth for URL shape, HTTP
 * method, and route name.
 *
 * Repeatable, so a single method can serve several verbs or paths.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $name = null,
    ) {}
}
