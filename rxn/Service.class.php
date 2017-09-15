<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
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
    public function __construct()
    {
        //intentionally left blank
    }

    /**
     * @param string $class_name
     *
     * @return object
     * @throws \Exception
     */
    public function get($class_name)
    {
        // validate that the class name actually exists
        if (!class_exists($class_name)) {
            throw new \Exception("$class_name is not a valid class name", 500);
        }

        // in the event that we're looking up the service class, return itself
        if ($class_name == Service::class) {
            return $this;
        }

        // if we already stored an instance of the class, return it
        if (self::has($class_name)) {
            return $this->instances[$class_name];
        }

        // generate an instance of the class
        $instance = $this->generateInstance($class_name);

        // add the instance into memory
        $this->addInstance($class_name, $instance);

        // return the class instance
        return $instance;
    }

    /**
     * @param $class_name
     *
     * @return object
     * @throws \Exception
     */
    private function generateInstance($class_name)
    {
        $reflection  = new \ReflectionClass($class_name);
        $class_name  = $reflection->getName();
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            throw new \Exception("Class '$class_name' does not have a valid constructor", 500);
        }
        $parameters = $constructor->getParameters();
        $args       = [];
        foreach ($parameters as $parameter) {
            if ($parameter->getClass()) {
                $class  = $parameter->getClass()->name;
                $args[] = $this->get($class);
            }
        }
        return $reflection->newInstanceArgs($args);
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

    /**
     * @param string $class_name
     * @param object $instance
     *
     * @return void
     */
    public function addInstance($class_name, $instance)
    {
        $this->instances[$class_name] = $instance;
    }
}