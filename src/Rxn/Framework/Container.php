<?php declare(strict_types=1);

namespace Rxn\Framework;

use \Psr\Container\ContainerInterface;
use \Rxn\Framework\Error\ContainerException;
use \Rxn\Framework\Error\ContainerNotFoundException;

class Container implements ContainerInterface
{
    /**
     * @var array $instances
     */
    public $instances = [];

    /**
     * Class names currently being resolved; used to detect cyclic
     * dependencies while autowiring constructors.
     *
     * @var array<string, true>
     */
    private $resolving = [];

    /**
     * abstract => concrete class-string or factory closure.
     * Lets apps map interfaces onto implementations without writing
     * a factory for every binding.
     *
     * @var array<string, string|callable>
     */
    private array $bindings = [];

    /**
     * Process-lifetime cache of ReflectionClass instances. The class
     * graph doesn't change at runtime, and constructing a
     * ReflectionClass each time `get()` is called is observable on
     * the dispatch hot path (see bench/ab/results — container
     * autowire moves +20-30%% under this cache).
     *
     * @var array<string, \ReflectionClass>
     */
    private static array $reflectionCache = [];

    /**
     * Same idea for `isService` — the answer is a function of the
     * class hierarchy, which is also fixed for the process lifetime.
     *
     * @var array<string, bool>
     */
    private static array $isServiceCache = [];

    /**
     * Pre-computed construction recipe per class. Each entry is a
     * directive describing how to fill the corresponding constructor
     * parameter slot — `['autowire', $class]`, `['default']`,
     * `['null']`, or `['fail', $param_name]`. `null` cache entry
     * means "no constructor; use newInstanceWithoutConstructor".
     *
     * Caching the recipe lets `generateInstance()` skip every
     * Reflection*Parameter call after the first resolution, which
     * is where the bulk of the autowire cost lives.
     *
     * @var array<string, list<array{0: string, 1?: string}>|null>
     */
    private static array $constructorPlanCache = [];

    /**
     * Precomputed normalised name of the Container class itself. Used
     * by `get()` to short-circuit when callers ask for the container.
     * Avoids a `ltrim($class_name, '\\') === ltrim(Container::class, '\\')`
     * pair on every dispatch — the input is already normalised, so a
     * single string compare against this constant is enough.
     */
    private const SELF_KEY = '\\Rxn\\Framework\\Container';

    /**
     * Container constructor.
     */
    public function __construct()
    {
        //intentionally left blank
    }

    /**
     * Bind an abstract type (usually an interface) to a concrete
     * class name or factory closure. Subsequent `get($abstract)`
     * calls resolve the bound target instead of trying to
     * instantiate the abstract directly.
     *
     *   $container->bind(UserRepo::class, PostgresUserRepo::class);
     *   $container->bind(Clock::class, fn($c) => new FrozenClock('2026-01-01'));
     *
     * Factory closures receive the container so they can pull
     * their own deps. Re-binding overwrites. Bindings are applied
     * before autowiring, so interface parameters on constructors
     * resolve to the bound concrete transparently.
     *
     * @param class-string                         $abstract
     * @param class-string|callable(Container): object $concrete
     */
    public function bind(string $abstract, string|callable $concrete): self
    {
        $this->bindings[$this->parseClassName($abstract)] = $concrete;
        return $this;
    }

    /**
     * Resolve and return an entry from the container.
     *
     * PSR-11 conformance: signature `get(string $id): mixed`
     * accepts any string id and returns whatever the entry
     * resolves to — which for Rxn is always an object, since
     * autowiring is class-driven. Throws
     * `ContainerNotFoundException` (PSR-11
     * `NotFoundExceptionInterface`) when the id doesn't resolve
     * to a known entry, and the broader `ContainerException`
     * (PSR-11 `ContainerExceptionInterface`) for other resolution
     * failures (circular dependency, malformed binding,
     * unconstrained constructor parameter).
     *
     * The optional `$parameters` array is non-PSR — Rxn-specific
     * sugar for "construct with these constructor args, but
     * autowire anything I didn't supply." Third-party PSR-11
     * consumers won't pass it.
     *
     * @return mixed
     * @throws ContainerException
     */
    public function get(string $class_name, array $parameters = []): mixed
    {
        // change every namespace to be absolute
        $class_name = $this->parseClassName($class_name);

        // Self-lookup short-circuit. $class_name is already in the
        // normalised "\Foo\Bar" form post-parseClassName, so a direct
        // compare against the precomputed self key is enough.
        if ($class_name === self::SELF_KEY) {
            return $this;
        }

        // bound abstract → resolve the bound target instead.
        if (isset($this->bindings[$class_name])) {
            $target = $this->bindings[$class_name];
            // Class-string binding wins over is_callable (which would
            // fire for any class that implements __invoke).
            if (is_string($target) && class_exists($target)) {
                return $this->get($target, $parameters);
            }
            if (is_callable($target)) {
                $instance = $target($this);
                $this->addInstance($class_name, $instance);
                return $instance;
            }
            throw new ContainerException("Binding for $class_name is neither a class name nor a callable");
        }

        if (!class_exists($class_name)) {
            // PSR-11 distinguishes "no such entry" from other
            // resolution failures. Subclass of ContainerException
            // so existing code that catches the broader type
            // continues to work.
            throw new ContainerNotFoundException("$class_name is not a valid class name");
        }

        // Canonicalise to the declared class casing so process-lifetime
        // caches don't grow with case-variant aliases of the same class.
        $class_name = $this->parseClassName(self::reflectionFor($class_name)->getName());

        // if we already stored an instance of a statically-bound service class, return it.
        // Direct isset rather than has() — PSR-11 has() now reports
        // "constructible" (class_exists), which is a different thing
        // from "have we cached an instance".
        if (self::isService($class_name)
            && isset($this->instances[$class_name])
        ) {
            return $this->instances[$class_name];
        }

        if (isset($this->resolving[$class_name])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . ' -> ' . $class_name;
            throw new ContainerException("Circular dependency detected while resolving: $chain");
        }
        $this->resolving[$class_name] = true;
        try {
            $instance = $this->generateInstance($class_name, $parameters);
        } finally {
            unset($this->resolving[$class_name]);
        }
        // $class_name is already normalised; skip addInstance() to
        // avoid a redundant parseClassName() call.
        $this->instances[$class_name] = $instance;

        return $instance;
    }

    /**
     * @param $class_name
     *
     * @return bool
     */
    private function isService($class_name)
    {
        return self::$isServiceCache[$class_name]
            ??= self::reflectionFor($class_name)->isSubclassOf(Service::class);
    }

    /**
     * Class-graph reflection lookup, cached for the process lifetime.
     */
    private static function reflectionFor(string $class_name): \ReflectionClass
    {
        return self::$reflectionCache[$class_name]
            ??= new \ReflectionClass($class_name);
    }

    /**
     * @param       $class_name
     * @param array $passed_parameters
     *
     * @return object
     * @throws ContainerException
     */
    private function generateInstance($class_name, array $passed_parameters)
    {
        $plan = self::constructorPlanFor($class_name);
        if ($plan === null) {
            $reflection = self::reflectionFor($class_name);
            return $reflection->newInstanceWithoutConstructor();
        }

        // Fast path: when callers don't override constructor params,
        // dispatch through the eval-compiled per-class factory. The
        // factory inlines `new $class($this->get(...), ...)` so we
        // skip ReflectionClass::newInstanceArgs() and the per-call
        // foreach over the directive plan.
        if ($passed_parameters === []) {
            $factory = self::$factoryCache[$class_name] ??= self::compileFactory($class_name, $plan);
            if ($factory !== null) {
                return $factory($this);
            }
        }

        // Slow path: parameter overrides — keep the runtime walker.
        $create_parameters = [];
        foreach ($plan as $key => $directive) {
            if (array_key_exists($key, $passed_parameters)) {
                $create_parameters[$key] = $passed_parameters[$key];
                continue;
            }
            switch ($directive[0]) {
                case 'autowire':
                    $create_parameters[$key] = $this->get($directive[1]);
                    break;
                case 'default':
                    $constructor = self::reflectionFor($class_name)->getConstructor();
                    $create_parameters[$key] = $constructor->getParameters()[$key]->getDefaultValue();
                    break;
                case 'null':
                    $create_parameters[$key] = null;
                    break;
                case 'fail':
                    throw new ContainerException(
                        "Cannot autowire parameter \${$directive[1]} of $class_name: "
                        . "no type hint, default value, or explicitly-passed value."
                    );
            }
        }

        $reflection = self::reflectionFor($class_name);
        return $reflection->newInstanceArgs($create_parameters);
    }

    /**
     * Per-class factory cache. Each entry is a closure
     * `static fn (Container $c) => new $class(...)` whose
     * constructor args are inlined to either `$c->get(<dep>)`
     * (for autowired class deps) or PHP literals (for defaults
     * resolved at compile time).
     *
     * Cache value is null when the plan can't be compiled
     * (e.g. a `'fail'` directive on a parameter that has no
     * autowireable type and no default — the runtime path keeps
     * the same exception message in that case).
     *
     * @var array<string, \Closure(self): object|null>
     */
    private static array $factoryCache = [];

    /**
     * Thin alias over `Rxn\Framework\Codegen\DumpCache::useDir()`.
     * Kept for the focused "configure the container's dump cache"
     * call site; apps configuring multiple components (Container
     * + Binder) typically just call `DumpCache::useDir()` once.
     */
    public static function useCacheDir(?string $dir): void
    {
        \Rxn\Framework\Codegen\DumpCache::useDir($dir);
    }

    public static function cacheDir(): ?string
    {
        return \Rxn\Framework\Codegen\DumpCache::dir();
    }

    /**
     * Drop the in-memory factory cache plus every dumped `*.php`
     * file. Other components sharing the same dump dir (Binder)
     * have their files purged too — a single cache dir holds
     * everything.
     */
    public static function clearCache(): void
    {
        self::$factoryCache = [];
        \Rxn\Framework\Codegen\DumpCache::purgeFiles();
    }

    /**
     * Generate `static fn (Container $c) => new $class($c->get(...), ...)`
     * for `$class_name`, given the already-compiled directive plan.
     * Returns null when any directive is a `'fail'` (the runtime
     * path then throws with the parameter name baked into the
     * error message — we don't try to reproduce that in compiled
     * code).
     *
     * @param list<array{0: string, 1?: mixed}> $plan
     */
    private static function compileFactory(string $class_name, array $plan): ?\Closure
    {
        $args = [];
        foreach ($plan as $directive) {
            switch ($directive[0]) {
                case 'autowire':
                    // $directive[1] is the pre-normalised FQCN literal.
                    $args[] = '$c->get(' . self::quoteString($directive[1]) . ')';
                    break;
                case 'default':
                    // Defaults can include object-creating expressions
                    // (e.g. `= new Bag`) and must be evaluated per
                    // constructor call. Fall back to runtime path.
                    return null;
                case 'null':
                    $args[] = 'null';
                    break;
                case 'fail':
                    // A required parameter has no autowireable type —
                    // we can't compile a factory for this class. Fall
                    // back to the runtime walker (which produces the
                    // ContainerException with the parameter name).
                    return null;
            }
        }
        $argList = implode(', ', $args);
        $literal = '\\' . ltrim($class_name, '\\');
        $body = "return static fn (\\Rxn\\Framework\\Container \$c) => new $literal($argList);";

        $closure = \Rxn\Framework\Codegen\DumpCache::load($body) ?? eval($body);

        if (!$closure instanceof \Closure) {
            throw new \RuntimeException("Container: failed to compile factory for $class_name");
        }
        return $closure;
    }

    private static function quoteString(string $s): string
    {
        return "'" . strtr($s, ["\\" => "\\\\", "'" => "\\'"]) . "'";
    }

    /**
     * Compile the construction recipe for a class, or fetch the
     * cached one. Returning `null` indicates "no constructor", so
     * the caller can skip straight to newInstanceWithoutConstructor.
     *
     * @return list<array{0: string, 1?: mixed}>|null
     */
    private static function constructorPlanFor(string $class_name): ?array
    {
        if (array_key_exists($class_name, self::$constructorPlanCache)) {
            return self::$constructorPlanCache[$class_name];
        }
        $constructor = self::reflectionFor($class_name)->getConstructor();
        if (!$constructor) {
            return self::$constructorPlanCache[$class_name] = null;
        }
        $plan = [];
        foreach ($constructor->getParameters() as $p) {
            if ($p->isDefaultValueAvailable()) {
                $plan[] = ['default'];
                continue;
            }
            if ($p->allowsNull()) {
                $plan[] = ['null'];
                continue;
            }
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                // Pre-normalise the target FQCN so recursive
                // get($directive[1]) skips repeated normalisation.
                $name = $type->getName();
                $normalised = '\\' . ltrim($name, '\\');
                $plan[] = ['autowire', $normalised];
                continue;
            }
            $plan[] = ['fail', $p->getName()];
        }
        return self::$constructorPlanCache[$class_name] = $plan;
    }

    /**
     * PSR-11 `has()`: return true iff `get($id)` would resolve
     * without throwing.
     *
     * Rxn's container autowires any constructible class. This
     * check verifies that the class exists, is instantiable
     * (not abstract/interface/trait/non-public constructor),
     * has no required non-autowireable constructor parameters,
     * and that all autowired constructor dependencies are
     * themselves resolvable (checked recursively to catch
     * unbound interface/abstract dependencies).
     *
     * Fast paths: self-lookup, declared bindings, and cached
     * instances short-circuit to true without further checking.
     *
     * Circular dependencies that pass through a binding or
     * cached instance are not caught — those are only detectable
     * at construction time. PSR-11 explicitly permits this.
     */
    public function has(string $id): bool
    {
        return $this->canResolve($id, []);
    }

    /**
     * Internal recursive implementation of `has()`.
     *
     * @param array<string, true> $visited  Canonical class names already
     *                                       in the current resolution chain,
     *                                       used for cycle detection.
     */
    private function canResolve(string $id, array $visited): bool
    {
        $class_name = $this->parseClassName($id);
        if ($class_name === self::SELF_KEY) {
            return true;
        }
        if (isset($this->bindings[$class_name])) {
            return true;
        }
        if (isset($this->instances[$class_name])) {
            return true;
        }
        if (!class_exists($class_name)) {
            return false;
        }

        // Canonicalise to the declared class casing using a one-shot
        // ReflectionClass so the reflectionFor() cache is only populated
        // under the canonical key, not a case-variant alias key.
        $class_name = $this->parseClassName((new \ReflectionClass($class_name))->getName());

        // Cycle detection: if this class is already in the current
        // resolution chain, get() would throw a circular-dependency error.
        if (isset($visited[$class_name])) {
            return false;
        }

        $reflection = self::reflectionFor($class_name);
        if (!$reflection->isInstantiable()) {
            return false;
        }

        $plan = self::constructorPlanFor($class_name);
        if ($plan === null) {
            return true;
        }

        // Mark this class as in-progress before recursing so that
        // transitive dependencies looping back here are caught.
        $visited[$class_name] = true;
        foreach ($plan as $directive) {
            switch ($directive[0]) {
                case 'fail':
                    return false;
                case 'autowire':
                    if (!$this->canResolve($directive[1], $visited)) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    private function parseClassName($class_name)
    {
        return "\\" . ltrim($class_name, '\\');
    }

    /**
     * @param string $class_name
     * @param object $instance
     *
     * @return void
     */
    public function addInstance($class_name, $instance)
    {
        $class_name                   = $this->parseClassName($class_name);
        $this->instances[$class_name] = $instance;
    }
}
