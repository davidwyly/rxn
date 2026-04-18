<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

use Rxn\Framework\Container;
use Rxn\Framework\Http\Middleware as MiddlewareContract;
use Rxn\Framework\Http\Router;

/**
 * Turn #[Route] + #[Middleware] attributes on controller classes
 * into live entries on a Router. Class-level #[Middleware] wraps
 * every method's route; method-level #[Middleware] adds to the
 * stack after class-level. Each middleware class is resolved
 * through the container so autowired constructor deps work.
 *
 *   (new Scanner($container))->register(
 *       $router,
 *       [ProductsController::class, OrdersController::class],
 *   );
 *
 * The handler stored on the route is `[ClassName, 'method']` —
 * callers pick the dispatch strategy (container->get + invoke,
 * direct call on a fresh instance, etc.).
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

            foreach ($routeAttrs as $attr) {
                /** @var Route $route */
                $route  = $attr->newInstance();
                $handle = [$class, $method->getName()];
                $entry  = $router->add($route->method, $route->path, $handle);
                if ($route->name !== null) {
                    $entry->name($route->name);
                }
                if ($stack !== []) {
                    $entry->middleware(...$stack);
                }
            }
        }
    }

    /**
     * @param list<\ReflectionAttribute<Middleware>> $attrs
     * @return list<MiddlewareContract>
     */
    private function resolveMiddlewareAttrs(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $attr) {
            /** @var Middleware $meta */
            $meta     = $attr->newInstance();
            $instance = $this->container->get($meta->class);
            if (!$instance instanceof MiddlewareContract) {
                throw new \InvalidArgumentException(
                    "#[Middleware({$meta->class})] does not resolve to a Rxn\\Framework\\Http\\Middleware"
                );
            }
            $out[] = $instance;
        }
        return $out;
    }
}
