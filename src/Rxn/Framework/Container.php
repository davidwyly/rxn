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

        // in the event that we're looking up the container class, return itself
        if (ltrim($class_name, '\\') === ltrim(Container::class, '\\')) {
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
        $this->addInstance($class_name, $instance);

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
        $reflection = self::reflectionFor($class_name);

        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return $reflection->newInstanceWithoutConstructor();
        }

        $constructor_parameters = $constructor->getParameters();

        $create_parameters = [];
        foreach ($constructor_parameters as $key => $constructor_parameter) {
            // use matching parameter
            if (array_key_exists($key, $passed_parameters)) {
                $create_parameters[$key] = $passed_parameters[$key];
                continue;
            }

            // if the parameter has a concrete class type, autowire it
            $type = $constructor_parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $create_parameters[$key] = $this->get($type->getName());
                continue;
            }

            // optional scalar parameter: use its default
            if ($constructor_parameter->isDefaultValueAvailable()) {
                $create_parameters[$key] = $constructor_parameter->getDefaultValue();
                continue;
            }

            // nullable scalar parameter with no default: pass null
            if ($constructor_parameter->allowsNull()) {
                $create_parameters[$key] = null;
                continue;
            }

            throw new ContainerException(
                "Cannot autowire parameter \${$constructor_parameter->getName()} of $class_name: "
                . "no type hint, default value, or explicitly-passed value."
            );
        }

        return $reflection->newInstanceArgs($create_parameters);
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
        $class_name = ltrim($class_name, '\\');
        return "\\" . $class_name;
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
