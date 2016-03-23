<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Service;

use \Rxn\Config;
use \Rxn\Data\Database;
use \Rxn\Api\Controller;
use \Rxn\Utility\Debug;

/**
 * Class Registry
 *
 * @package Rxn\Service
 */
class Registry
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
     * @param Config $config
     * @param Database $database
     * @return void
     */
    public function __construct(Config $config, Database $database) {
        $this->config = $config;
        $this->database = $database;

        // register self and dependencies
        $this->registerObject($this);
        $this->registerObject($config);
        $this->registerObject($database);
        $this->fetchTables();
        $this->registerAutoload();
    }

    /**
     * @param object $object
     * @return void
     */
    private function registerObject($object) {
        $class = $this->getClassByObject($object);
        $this->registerClass($class);
    }

    /**
     * @param object $object
     * @return string
     * @throws \Exception
     */
    private function getClassByObject($object) {
        if (!is_object($object)) {
            throw new \Exception("Expected object");
        }
        $reflection = new \ReflectionObject($object);
        return $reflection->getName();
    }

    /**
     * @param Database $database
     * @return void
     */
    public function initialize(Database $database) {
        $this->fetchTables($database);
    }

    /**
     * @param string $classReference
     * @return void
     * @throws \Exception
     */
    public function registerClass($classReference) {
        if (!class_exists($classReference)) {
            throw new \Exception("Class '$classReference' has not been instantiated");
        }
        $classReflection = new \ReflectionClass($classReference);
        $classPath = $classReflection->getFileName();
        $shortname = $this->getShortnameByReference($classReference);
        $namespace = $this->getNamespaceByReference($classReference);
        $directory = $this->getDirectoryByPath($classPath);
        $file = $this->getFileByPath($classPath);
        $extension = $this->getExtensionByPath($classPath);
        $this->classes[$classReference] = [
            'shortname' => $shortname,
            'namespace' => $namespace,
            'directory' => $directory,
            'file' => $file,
            'extension' => $extension,
            'path' => $classPath,
        ];
    }

    /**
     * @param $controllerName
     * @param $controllerVersion
     *
     * @return null
     */
    public function registerController($controllerName, $controllerVersion) {
        $controllerRef = Controller::getRef($controllerName, $controllerVersion);
        $this->controllers[$controllerVersion][$controllerName] = $controllerRef;
        return null;
    }

    /**
     *
     */
    public function sortClasses() {
        ksort($this->classes);
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    private function getDirectoryByPath($path) {
        return preg_replace('#(^.+)\/(.+$)#','$1',$path);
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    private function getFileByPath($path) {
        return preg_replace('#(^.+\/)?(.+$)#','$2',$path);
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    private function getExtensionByPath($path) {
        return preg_replace('#(^.+?)(\..+$)#','$2',$path);
    }

    /**
     * @param $reference
     *
     * @return mixed
     */
    private function getNamespaceByReference($reference) {
        return preg_replace('#(^.+)\\\\(.+$)#','$1',$reference);
    }

    /**
     * @param $reference
     *
     * @return mixed
     */
    private function getShortnameByReference($reference) {
        return preg_replace('#(^.+)\\\\(.+$)#','$2',$reference);
    }

    /**
     * @return bool
     */
    private function fetchTables() {
        $databaseName = $this->database->getName();
        $sql = "
			SELECT
				`TABLE_NAME`
			FROM information_schema.tables AS t
			WHERE t.table_schema LIKE ?
				AND t.table_type = 'BASE TABLE'
			";

        $tables = $this->database->fetchArray($sql,[$databaseName],true,1);
        if (!$tables) {
            return false;
        }
        $this->tables[$databaseName] = $tables;

        return true;
    }

    /**
     *
     */
    private function registerAutoload()
    {
        spl_autoload_register(array($this, 'load'));
    }

    /**
     * @param string $classReference
     * @param string $extension
     * @return void
     */
    private function load($classReference, $extension = '.class.php')
    {
        // determine the file path of the class reference
        $classPath = $this->getClassPathByClassReference($classReference, $extension);

        // load the class
        include($classPath);

        // register the class
        $this->registerClass($classReference);
    }

    /**
     * @param string $classReference
     * @param string $extension
     * @return string
     * @throws \Exception
     */
    private function getClassPathByClassReference($classReference, $extension)
    {
        // break the class namespace into an array
        $pathArray = explode("\\",$classReference);

        // remove the root namespace from the array
        $root = mb_strtolower(array_shift($pathArray));

        if ($root != $this->config->appFolder) {
            if ($root != $this->config->vendorFolder) {
                throw new \Exception("Root path '$root' in reference '$classReference' not defined in config");
            }
        }

        // determine the name of the class without the namespace
        $classShortName = array_pop($pathArray);

        // convert the namespaces into lowercase
        foreach($pathArray as $key=>$value) {
            $pathArray[$key] = mb_strtolower($value);
        }

        // tack the short name of the class back onto the end
        array_push($pathArray,$classShortName);

        // convert back into a string for directory reference
        $classPath = implode("/",$pathArray);
        $loadPathRoot = realpath(__DIR__ . "/../../");
        $loadPathClass = "/" . $root . "/" . $classPath . $extension;
        $loadPath = $loadPathRoot . $loadPathClass;

        if (!file_exists($loadPath)) {
            // 400 level error if the controller is incorrect
            if (mb_strpos($classPath,'controller')) {
                throw new \Exception("Controller '$classReference' does not exist",400);
            }
            // 500 level error otherwise; only throw the partial path for security purposes
            throw new \Exception("Load path '$loadPathClass' does not exist",501);
        }
        $loadPath = realpath($loadPath);

        // validate the path
        if (!file_exists($loadPath)) {
            throw new \Exception("Cannot autoload path '$loadPath'");
        }
        return $loadPath;
    }
}