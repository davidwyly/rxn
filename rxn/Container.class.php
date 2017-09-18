<?php
/**
 * This file is part of the Rxn (Reaction) PHP API App
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn;

use \Rxn\Error\ContainerException;

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
     *
     * @return object
     * @throws ContainerException
     */
    public function get($class_name)
    {
        // change every namespace to be absolute
        $class_name = ltrim($class_name, '\\');
        $class_name = "\\" . $class_name;

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
        $instance = $this->generateInstance($class_name);
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
     * @param $class_name
     *
     * @return object
     * @throws ContainerException
     */
    private function generateInstance($class_name)
    {
        $reflection  = new \ReflectionClass($class_name);
        $class_name  = $reflection->getName();
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            throw new ContainerException("Class '$class_name' does not have a valid constructor");
        }
        $parameters = $constructor->getParameters();
        $args       = [];
        foreach ($parameters as $parameter) {

            // for an instance to be generated from the constructor, insert null if it can be optionally null
            if ($parameter->allowsNull()) {
                $args[] = null;
                continue;
            }

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