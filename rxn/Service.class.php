<?php

namespace Rxn;

class Service
{
    public $instances;

    public function __construct() {

    }

    public function get($className) {
        if (!class_exists($className)) {
            throw new \Exception("$className is not a valid class name",500);
        }
        if (self::has($className)) {
            return $this->instances[$className];
        }
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();
        $args = array();
        foreach ($parameters as $parameter) {
            if ($parameter->getClass()) {
                $class = $parameter->getClass()->name;
                $args[] = $this->get($class);
            }
        }
        $instance = $reflection->newInstanceArgs($args);
        $this->addInstance($className,$instance);
        return $instance;
    }

    public function has($className) {
        if (isset($this->instances[$className])) {
            return true;
        }
        return false;
    }

    private function addInstance($className,$instance) {
        $this->instances[$className] = $instance;
    }
}