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

namespace Rxn\Framework\Service;

use \Rxn\Framework\BaseConfig;
use \Rxn\Framework\Service;
use \Rxn\Framework\Data\Database;
use \Rxn\Framework\Error\RegistryException;

class Registry extends Service
{
    /**
     * @var BaseConfig
     */
    private $config;

    /**
     * @var Database
     */
    private $database;

    public $classes;
    public $tables;
    public $records;
    public $controllers;
    public $contracts;

    /**
     * @param BaseConfig   $config
     * @param Database $database
     *
     * @throws RegistryException
     * @throws \Rxn\Framework\Error\QueryException
     */
    public function __construct(BaseConfig $config, Database $database)
    {
        $this->config = $config;
        $this->database =  $database;

        /**
         * register self and dependencies
         */
        $this->registerObject($this);
        $this->registerObject($this->config);
        $this->registerObject($this->database);
        $this->fetchTables();
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
     * @throws \Rxn\Framework\Error\QueryException
     */
    public function initialize()
    {
        $this->fetchTables();
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
     * @throws \Rxn\Framework\Error\QueryException
     */
    private function fetchTables()
    {
        $sql = /** @lang SQL * */
            "
            SELECT
                `TABLE_NAME`
            FROM information_schema.tables AS t
            WHERE t.table_schema LIKE ?
                AND t.table_type = 'BASE TABLE'
            ";

        $database_name = $this->database->getName();
        $params        = [$database_name];
        $tables        = $this->database->fetchArray($sql, $params);

        if (!$tables) {
            return false;
        }

        $this->tables[$database_name] = $tables;
        return true;
    }
}
