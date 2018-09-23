<?php declare(strict_types=1);

namespace Rxn\Framework;

use \Rxn\Framework\Error\ContainerException;

class Container
{
    /**
     * @var array $instances
     */
    public $instances;

    /**
     * Container constructor.
     */
    public function __construct()
    {
        //intentionally left blank
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

        if (!class_exists($class_name)) {
            throw new ContainerException("$class_name is not a valid class name");
        }

        // in the event that we're looking up the container class, return itself
        if ($class_name == Container::class) {
            return $this;
        }

        // if we already stored an instance of a statically-bound service class, return it
        if (self::isService($class_name)
            && self::has($class_name)
        ) {
            return $this->instances[$class_name];
        }

        // generate instance and add it into memory
        $instance = $this->generateInstance($class_name, $parameters);
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
        $reflection = new \ReflectionClass($class_name);
        return $reflection->isSubclassOf(Service::class);
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
        $reflection = new \ReflectionClass($class_name);

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

            // if it doesn't match anything, pass null if possible
            if ($constructor_parameter->allowsNull()) {
                $create_parameters[$key] = null;
                continue;
            }

            // otherwise, use method injection instantiation
            if ($constructor_parameter->getClass()) {
                $class                   = $constructor_parameter->getClass()->name;
                $create_parameters[$key] = $this->get($class);
                continue;
            }
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
