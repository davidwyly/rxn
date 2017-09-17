<?php
/**
 * This file is part of the Rxn (Reaction) PHP API Framework
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn;

use \Rxn\Error\ServiceException;

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
     * @throws ServiceException
     */
    public function get($class_name)
    {
        // validate that the class name actually exists
        if (!class_exists($class_name)) {
            throw new ServiceException("$class_name is not a valid class name");
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
     * @throws ServiceException
     */
    private function generateInstance($class_name)
    {
        $reflection  = new \ReflectionClass($class_name);
        $class_name  = $reflection->getName();
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            throw new ServiceException("Class '$class_name' does not have a valid constructor");
        }
        $parameters = $constructor->getParameters();
        $args       = [];
        foreach ($parameters as $parameter) {
            if ($parameter->getClass()
                && !$parameter->allowsNull()
            ) {
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