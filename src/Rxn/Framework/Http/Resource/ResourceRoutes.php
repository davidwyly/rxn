<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Resource;

use Psr\Http\Server\MiddlewareInterface;
use Rxn\Framework\Http\Route;

/**
 * Handle bag returned from `ResourceRegistrar::register()`.
 * Holds the five `Route` instances created for the resource so
 * callers can attach per-op middleware after registration.
 *
 *   $routes = ResourceRegistrar::register($router, '/products', $crud, ...);
 *
 *   // Same auth on every CRUD route — applies to all five.
 *   $routes->middleware($bearerAuth);
 *
 *   // Stricter check on destructive ops — chains additional
 *   // middleware on the specific Route handle.
 *   $routes->update->middleware($adminOnly);
 *   $routes->delete->middleware($adminOnly);
 *
 * The bag is intentionally a struct of public readonly fields:
 * one per resource operation. Apps that want to iterate (`foreach
 * ($routes->all() as $r) { ... }`) get an `all()` helper.
 */
final class ResourceRoutes
{
    public function __construct(
        public readonly Route $create,
        public readonly Route $search,
        public readonly Route $read,
        public readonly Route $update,
        public readonly Route $delete,
    ) {}

    /**
     * Attach the same middleware stack to every CRUD route in
     * one call. Chains return `$this` so registration + auth
     * wiring can be a one-liner:
     *
     *   ResourceRegistrar::register($router, '/products', $crud, ...)
     *       ->middleware(new BearerAuth($resolver));
     */
    public function middleware(MiddlewareInterface ...$middlewares): self
    {
        if ($middlewares === []) {
            return $this;
        }
        foreach ($this->all() as $route) {
            $route->middleware(...$middlewares);
        }
        return $this;
    }

    /**
     * Iterate the five Route handles in registration order
     * (create / search / read / update / delete). Useful for
     * generic loops; per-op customization should target the
     * specific public field instead.
     *
     * @return list<Route>
     */
    public function all(): array
    {
        return [$this->create, $this->search, $this->read, $this->update, $this->delete];
    }
}
