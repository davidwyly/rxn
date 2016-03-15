<?php

namespace Rxn\Service;

use \Rxn\Config;
use \Rxn\Data\Database;
use \Rxn\Api\Controller;
use \Rxn\Utility\Debug;

class Registry
{
    static public $classes;
    static public $tables;
    static public $records;
    static public $controllers;
    static public $contracts;

    public function __construct() {
        //
    }

    public function initialize(Config $config) {
        $this->fetchTables(Database::getName());
    }

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
        self::$classes[$classReference] = [
            'shortname' => $shortname,
            'namespace' => $namespace,
            'directory' => $directory,
            'file' => $file,
            'extension' => $extension,
            'path' => $classPath,
        ];
        return null;
    }

    static public function registerController($controllerName, $controllerVersion) {
        $controllerRef = Controller::getRef($controllerName, $controllerVersion);
        self::$controllers[$controllerVersion][$controllerName] = $controllerRef;
        return null;
    }


    public function sortClasses() {
        ksort(self::$classes);
    }

    private function getDirectoryByPath($path) {
        return preg_replace('#(^.+)\/(.+$)#','$1',$path);
    }

    private function getFileByPath($path) {
        return preg_replace('#(^.+\/)?(.+$)#','$2',$path);
    }

    private function getExtensionByPath($path) {
        return preg_replace('#(^.+?)(\..+$)#','$2',$path);
    }

    private function getNamespaceByReference($reference) {
        return preg_replace('#(^.+)\\\\(.+$)#','$1',$reference);
    }

    private function getShortnameByReference($reference) {
        return preg_replace('#(^.+)\\\\(.+$)#','$2',$reference);
    }

    private function fetchTables($databaseName) {
        $sql = "
			SELECT
				`TABLE_NAME`
			FROM information_schema.tables AS t
			WHERE t.table_schema LIKE ?
				AND t.table_type = 'BASE TABLE'
			";
        $tables = Database::fetchArray($sql,[$databaseName],true,1);
        if (!$tables) {
            return false;
        }
        self::$tables[$databaseName] = $tables;

        return true;
    }
}