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

namespace Rxn\Service;

use \Rxn\Config;
use \Rxn\ApplicationService;
use \Rxn\Data\Database;
use \Rxn\Utility\MultiByte;
use \Rxn\Error\RegistryException;

class Registry extends ApplicationService
{
    /**
     * @var Config
     */
    public $config;

    /**
     * @var Database
     */
    public $database;
    public $classes;
    public $tables;
    public $records;
    public $controllers;
    public $contracts;

    /**
     * @param Config   $config
     * @param Database $database
     *
     * @throws RegistryException
     * @throws \Rxn\Error\QueryException
     */
    public function __construct(Config $config, Database $database)
    {
        // register self and dependencies
        $this->registerObject($this);
        $this->registerObject($config);
        $this->registerObject($database);
        $this->fetchTables($database);
        $this->registerAutoload($config);
    }

    /**
     * @param object $object
     *
     * @return void
     * @throws RegistryException
     */
    private function registerObject($object)
    {
        $class = $this->getClassByObject($object);
        $this->registerClass($class);
    }

    /**
     * @param object $object
     *
     * @return string
     * @throws RegistryException
     */
    private function getClassByObject($object)
    {
        if (!is_object($object)) {
            throw new RegistryException("Expected object");
        }
        $reflection = new \ReflectionObject($object);
        return $reflection->getName();
    }

    /**
     * @param Database $database
     *
     * @throws \Rxn\Error\QueryException
     */
    public function initialize(Database $database)
    {
        $this->fetchTables($database);
    }

    /**
     * @param string $class_reference
     *
     * @return void
     * @throws RegistryException
     */
    public function registerClass($class_reference)
    {
        if (!class_exists($class_reference)) {
            if (!interface_exists($class_reference)) {
                throw new RegistryException("Class or interface '$class_reference' has not been instantiated");
            }
        }
        $class_reflection = new \ReflectionClass($class_reference);
        $class_path       = $class_reflection->getFileName();
        $shortname        = $this->getShortnameByReference($class_reference);
        $namespace        = $this->getNamespaceByReference($class_reference);
        $directory        = $this->getDirectoryByPath($class_path);
        $file             = $this->getFileByPath($class_path);
        $extension        = $this->getExtensionByPath($class_path);

        $this->classes[$class_reference] = [
            'shortname' => $shortname,
            'namespace' => $namespace,
            'directory' => $directory,
            'file'      => $file,
            'extension' => $extension,
            'path'      => $class_path,
        ];
    }

    /**
     *
     */
    public function sortClasses()
    {
        ksort($this->classes);
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    private function getDirectoryByPath($path)
    {
        return preg_replace('#(^.+)\/(.+$)#', '$1', $path);
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    private function getFileByPath($path)
    {
        return preg_replace('#(^.+\/)?(.+$)#', '$2', $path);
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    private function getExtensionByPath($path)
    {
        return preg_replace('#(^.+?)(\..+$)#', '$2', $path);
    }

    /**
     * @param $reference
     *
     * @return mixed
     */
    private function getNamespaceByReference($reference)
    {
        return preg_replace('#(^.+)\\\\(.+$)#', '$1', $reference);
    }

    /**
     * @param $reference
     *
     * @return mixed
     */
    private function getShortnameByReference($reference)
    {
        return preg_replace('#(^.+)\\\\(.+$)#', '$2', $reference);
    }

    /**
     * @param Database $database
     *
     * @return bool
     * @throws \Rxn\Error\QueryException
     */
    private function fetchTables(Database $database)
    {
        $sql = /** @lang SQL * */
            "
            SELECT
                `TABLE_NAME`
            FROM information_schema.tables AS t
            WHERE t.table_schema LIKE ?
                AND t.table_type = 'BASE TABLE'
            ";

        $database_name = $database->getName();
        $params        = [$database_name];
        $tables        = $database->fetchArray($sql, $params, $database->allow_caching);

        if (!$tables) {
            return false;
        }

        $this->tables[$database_name] = $tables;
        return true;
    }

    /**
     * @param Config $config
     */
    private function registerAutoload(Config $config)
    {
        spl_autoload_register(function ($class_name) use ($config) {
            $this->load($config, $class_name);
        });
    }

    /**
     * @param Config $config
     * @param        $class_reference
     *
     * @return bool
     * @throws RegistryException
     */
    private function load(Config $config, $class_reference)
    {
        foreach ($config->autoload_extensions as $extension) {
            try {
                $class_path = $this->getClassPathByClassReference($config, $class_reference, $extension);
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!isset($class_path)) {
            return false;
        }

        // load the class
        /** @noinspection PhpIncludeInspection */
        include($class_path);

        // register the class
        $this->registerClass($class_reference);

        return true;
    }

    /**
     * @param Config $config
     * @param string $class_reference
     * @param string $extension
     *
     * @return string
     * @throws RegistryException
     */
    private function getClassPathByClassReference(Config $config, $class_reference, $extension)
    {
        // break the class namespace into an array
        $path_array = explode("\\", $class_reference);

        // remove the root namespace from the array
        $root = MultiByte::strtolower(array_shift($path_array));

        if ($root != $config->framework_folder) {
            if ($root != $config->organization_folder) {
                throw new RegistryException("Root path '$root' in reference '$class_reference' not defined in config");
            }
        }

        // determine the name of the class without the namespace
        $class_short_name = array_pop($path_array);

        // convert the namespaces into lowercase
        foreach ($path_array as $key => $value) {
            $path_array[$key] = MultiByte::strtolower($value);
        }

        // tack the short name of the class back onto the end
        array_push($path_array, $class_short_name);

        // convert back into a string for directory reference
        $class_path      = implode("/", $path_array);
        $load_path_root  = realpath(__DIR__ . "/../../");
        $load_path_class = "/" . $root . "/" . $class_path . $extension;
        $load_path       = $load_path_root . $load_path_class;

        if (!file_exists($load_path)) {
            $controller_exists = (MultiByte::strpos($class_path, 'controller') !== false);

            // 400 level error if the controller is incorrect
            if (!$controller_exists) {
                throw new RegistryException("Controller '$class_reference' does not exist", 400);
            }

            // 500 level error otherwise; only throw the partial path for security purposes
            throw new RegistryException("Load path '$load_path_class' does not exist", 501);
        }
        $load_path = realpath($load_path);

        // validate the path
        if (!file_exists($load_path)) {
            throw new RegistryException("Cannot autoload path '$load_path'");
        }
        return $load_path;
    }
}