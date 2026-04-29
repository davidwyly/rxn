<?php declare(strict_types=1);

namespace Rxn\Framework;

use \Rxn\Framework\Error\ContainerException;

class Container
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
     * parameter slot — `['autowire', $class]`, `['default', $value]`,
     * `['null']`, or `['fail', $param_name]`. `null` cache entry
     * means "no constructor; use newInstanceWithoutConstructor".
     *
     * Caching the recipe lets `generateInstance()` skip every
     * Reflection*Parameter call after the first resolution, which
     * is where the bulk of the autowire cost lives.
     *
     * @var array<string, list<array{0: string, 1?: mixed}>|null>
     */
    private static array $constructorPlanCache = [];

    /**
     * Memoise normalised class names. parseClassName is a pure
     * function (`'\\' . ltrim($input, '\\')`); same input always
     * produces the same output, and it gets called multiple times
     * per `get()` (entry call + recursive autowire + addInstance).
     *
     * @var array<string, string>
     */
    private static array $parsedNameCache = [];

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
     * @param string $class_name
     * @param array  $parameters
     *
     * @return object
     * @throws ContainerException
     */
    public function get($class_name, array $parameters = [])
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
            throw new ContainerException("$class_name is not a valid class name");
        }

        // if we already stored an instance of a statically-bound service class, return it
        if (self::isService($class_name)
            && self::has($class_name)
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
        $reflection = self::reflectionFor($class_name);
        if ($plan === null) {
            return $reflection->newInstanceWithoutConstructor();
        }

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
                    $create_parameters[$key] = $directive[1];
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

        return $reflection->newInstanceArgs($create_parameters);
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
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                // Pre-normalise the target FQCN so the recursive
                // get($directive[1]) lands on the parseClassName cache
                // immediately instead of paying for ltrim + concat.
                $name = $type->getName();
                $normalised = self::$parsedNameCache[$name]
                    ??= '\\' . ltrim($name, '\\');
                $plan[] = ['autowire', $normalised];
                continue;
            }
            if ($p->isDefaultValueAvailable()) {
                $plan[] = ['default', $p->getDefaultValue()];
                continue;
            }
            if ($p->allowsNull()) {
                $plan[] = ['null'];
                continue;
            }
            $plan[] = ['fail', $p->getName()];
        }
        return self::$constructorPlanCache[$class_name] = $plan;
    }

    /**
     * @param $class_name
     *
     * @return bool
     */
    public function has($class_name)
    {
        if (isset($this->instances[$class_name])) {
            return true;
        }
        return false;
    }

    private function parseClassName($class_name)
    {
        return self::$parsedNameCache[$class_name]
            ??= "\\" . ltrim($class_name, '\\');
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
