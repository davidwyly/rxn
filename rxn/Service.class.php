<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

/**
 * Class Service
 *
 * @package Rxn
 */
class Service
{
    /**
     * @var array $instances
     */
    public $instances;

    /**
     * Service constructor.
     */
    public function __construct() {

    }

    /**
     * @param string $className
     *
     * @return object
     * @throws \Exception
     */
    public function get($className) {
        if (!class_exists($className)) {
            throw new \Exception("$className is not a valid class name",500);
        }
        if (self::has($className)) {
            return $this->instances[$className];
        }
        $reflection = new \ReflectionClass($className);
        $className = $reflection->getName();
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

    /**
     * @param $className
     *
     * @return bool
     */
    public function has($className) {
        if (isset($this->instances[$className])) {
            return true;
        }
        return false;
    }

    /**
     * @param string $className
     * @param object $instance
     *
     * @return void
     */
    public function addInstance($className,$instance) {
        $this->instances[$className] = $instance;
    }
}