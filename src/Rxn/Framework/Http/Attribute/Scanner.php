<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Psr\Http\Server\MiddlewareInterface;
use Rxn\Framework\Container;
use Rxn\Framework\Http\Router;
use Rxn\Framework\Http\Versioning\Deprecation;

/**
 * Turn #[Route] + #[Middleware] + #[Version] attributes on
 * controller classes into live entries on a Router.
 *
 *   - **Class-level `#[Middleware]`** wraps every method's route;
 *     method-level `#[Middleware]` adds to the stack after
 *     class-level.
 *   - **Class-level `#[Version]`** prefixes every route in the
 *     class with `/$version`. Method-level `#[Version]` overrides
 *     class-level when both are present.
 *   - **`#[Version]` with `deprecatedAt` / `sunsetAt`** auto-
 *     attaches a `Versioning\Deprecation` middleware to the
 *     route — outgoing responses gain RFC 8594 `Deprecation:` /
 *     `Sunset:` headers without per-handler boilerplate.
 *
 *   (new Scanner($container))->register(
 *       $router,
 *       [ProductsController::class, OrdersController::class],
 *   );
 *
 * Each middleware class is resolved through the container so
 * autowired constructor deps work. The handler stored on the
 * route is `[ClassName, 'method']` — callers pick the dispatch
 * strategy (container->get + invoke, direct call on a fresh
 * instance, etc.).
 */
final class Scanner
{
    public function __construct(private Container $container) {}

    /**
     * @param list<class-string> $controllers
     */
    public function register(Router $router, array $controllers): void
    {
        foreach ($controllers as $class) {
            $this->registerOne($router, $class);
        }
    }

    /** @param class-string $class */
    private function registerOne(Router $router, string $class): void
    {
        if (!class_exists($class)) {
            return;
        }
        $ref = new \ReflectionClass($class);
        $classMiddlewares = $this->resolveMiddlewareAttrs($ref->getAttributes(Middleware::class));
        $classVersion     = $this->firstVersion($ref->getAttributes(Version::class));

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }
            $routeAttrs = $method->getAttributes(Route::class);
            if ($routeAttrs === []) {
                continue;
            }
            $methodMiddlewares = $this->resolveMiddlewareAttrs($method->getAttributes(Middleware::class));
            $stack             = array_merge($classMiddlewares, $methodMiddlewares);

            // Method-level #[Version] wins over class-level. The
            // effective version applies to every #[Route] on this
            // method (the same handler can serve multiple verbs /
            // paths via repeated #[Route], but they all share the
            // method's version annotation).
            $methodVersion    = $this->firstVersion($method->getAttributes(Version::class));
            $effectiveVersion = $methodVersion ?? $classVersion;

            // Auto-attach the deprecation middleware once per
            // method, not per Route attribute, so the same response
            // header doesn't get added twice for repeat-routed
            // handlers.
            //
            // Prepend, not append: Pipeline runs middleware in
            // registration order, so the first one wraps every
            // later one. We need Deprecation OUTERMOST so its
            // `process()` decorates whatever response comes back —
            // including short-circuit responses from inner
            // middleware (e.g. auth's 401, rate-limit's 429). If
            // we appended, those failure paths would leave the
            // route without the documented headers.
            $perRouteStack = $stack;
            if ($effectiveVersion !== null && self::hasDeprecation($effectiveVersion)) {
                array_unshift(
                    $perRouteStack,
                    new Deprecation(
                        $effectiveVersion->deprecatedAt,
                        $effectiveVersion->sunsetAt,
                    ),
                );
            }

            foreach ($routeAttrs as $attr) {
                /** @var Route $route */
                $route   = $attr->newInstance();
                $path    = $effectiveVersion === null
                    ? $route->path
                    : self::prefixWithVersion($route->path, $effectiveVersion->version);
                $handle  = [$class, $method->getName()];
                $entry   = $router->add($route->method, $path, $handle);
                if ($route->name !== null) {
                    $entry->name($route->name);
                }
                if ($perRouteStack !== []) {
                    $entry->middleware(...$perRouteStack);
                }
            }
        }
    }

    /**
     * @param list<\ReflectionAttribute<Middleware>> $attrs
     * @return list<MiddlewareInterface>
     */
    private function resolveMiddlewareAttrs(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $attr) {
            /** @var Middleware $meta */
            $meta     = $attr->newInstance();
            $instance = $this->container->get($meta->class);
            if (!$instance instanceof MiddlewareInterface) {
                throw new \InvalidArgumentException(
                    "#[Middleware({$meta->class})] does not resolve to a Psr\\Http\\Server\\MiddlewareInterface"
                );
            }
            $out[] = $instance;
        }
        return $out;
    }

    /**
     * Take the first `#[Version]` attribute on a method or class.
     * `#[Version]` is not declared `IS_REPEATABLE`, so there's at
     * most one, but reflection still hands us a list — we just
     * pick element zero or null.
     *
     * @param list<\ReflectionAttribute<Version>> $attrs
     */
    private function firstVersion(array $attrs): ?Version
    {
        if ($attrs === []) {
            return null;
        }
        /** @var Version $v */
        $v = $attrs[0]->newInstance();
        return $v;
    }

    private static function hasDeprecation(Version $version): bool
    {
        return $version->deprecatedAt !== null || $version->sunsetAt !== null;
    }

    /**
     * `'/products/{id:int}'` + version `'v1'` → `'/v1/products/{id:int}'`.
     *
     * If the route path already starts with the version prefix,
     * pass it through unchanged — apps that hand-prefix their
     * paths AND mark them with `#[Version]` shouldn't end up with
     * `/v1/v1/...`.
     *
     * The version label is trimmed of leading and trailing slashes
     * before the prefix is built, so `'v1'` / `'/v1'` / `'v1/'` /
     * `'/v1/'` all produce the same `/v1` prefix and concatenation
     * never yields a double slash. An empty trimmed label is
     * rejected — `#[Version('')]` is meaningless.
     */
    private static function prefixWithVersion(string $path, string $version): string
    {
        $label = trim($version, '/');
        if ($label === '') {
            throw new \InvalidArgumentException(
                "#[Version] label cannot be empty (got '$version')"
            );
        }
        $prefix = '/' . $label;
        if (str_starts_with($path, $prefix . '/') || $path === $prefix) {
            return $path;
        }
        // `$path` is conventionally rooted at `/` — concat is enough.
        return $prefix . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
}
