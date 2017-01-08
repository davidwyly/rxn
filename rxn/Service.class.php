<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

use \Rxn\Utility\Debug;

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
        //nothing is intentionally here
    }

    /**
     * @param string $className
     *
     * @return object
     * @throws \Exception
     */
    public function get($className) {
        // validate that the class name actually exists
        if (!class_exists($className)) {
            throw new \Exception("$className is not a valid class name",500);
        }

        // in the event that we're looking up the service class itself, return the service class
        if ($className == Service::class) {
            return $this;
        }

        // if the service has already stored an instance of the class, return it
        if (self::has($className)) {
            return $this->instances[$className];
        }

        // generate an instance of the class
        $instance = $this->generateInstance($className);

        // add the instance into memory
        $this->addInstance($className,$instance);

        // return the class instance
        return $instance;
    }

    /**
     * @param $className
     * @return object
     * @throws \Exception
     */
    private function generateInstance($className) {
        $reflection = new \ReflectionClass($className);
        $className = $reflection->getName();
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            throw new \Exception("Class '$className' does not have a valid constructor",500);
        }
        $parameters = $constructor->getParameters();
        $args = array();
        foreach ($parameters as $parameter) {
            if ($parameter->getClass()) {
                $class = $parameter->getClass()->name;
                $args[] = $this->get($class);
            }
        }
        return $reflection->newInstanceArgs($args);
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