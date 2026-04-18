<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

/**
 * Attach a middleware to one method or every route on a class:
 *
 *   #[Middleware(Auth::class)]
 *   #[Middleware(RateLimit::class)]
 *   class ProductsController { ... }
 *
 *   #[Route('GET', '/admin/reports')]
 *   #[Middleware(AdminOnly::class)]
 *   public function reports() { ... }
 *
 * Scanner resolves each class name through the container so
 * middlewares that take constructor deps (loggers, rate limiters,
 * etc.) get wired the same way a regular service would.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Middleware
{
    /** @param class-string $class */
    public function __construct(public readonly string $class) {}
}
