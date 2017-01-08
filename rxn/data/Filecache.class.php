<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Config;
use \Rxn\Utility\Debug;

/**
 * Class Filecache
 *
 * @package Rxn\Data
 */
class Filecache
{
    /**
     * @var string
     */
    public $directory;

    const EXTENSION = 'filecache';

    /**
     * Filecache constructor.
     *
     * @param Config $config
     */
    public function __construct(Config $config) {
        $this->setDirectory($config);
    }

    /**
     * @param Config $config
     *
     * @throws \Exception
     */
    private function setDirectory(Config $config) {
        $directory = __DIR__ . "/" . $config->fileCacheDirectory;
        if (!file_exists($directory)) {
            throw new \Exception("Cache $directory doesn't exist; it may need to be created",500);
        }
        $this->directory = realpath(__DIR__ . "/" . $config->fileCacheDirectory);
    }

    /**
     * @param string  $class
     * @param array   $parameters
     *
     * @return bool|mixed
     * @throws \Exception
     */
    public function getObject($class, array $parameters) {
        if (!class_exists($class)) {
            throw new \Exception("Invalid class name '$class'",500);
        }
        $reflection = new \ReflectionClass($class);
        $shortName = $reflection->getShortName();
        $parameterHash = $this->getParametersHash($parameters);
        $fileName = $this->getFileName($parameterHash);
        $directory = $this->getDirectory($shortName);
        $filePath = $this->getFilePath($directory,$fileName);
        if (!is_readable($this->directory)) {
            throw new \Exception("$directory must be readable to cache; check owner and permissions",500);
        }
        if (!file_exists($filePath)) {
            return false;
        }
        $serializedObject = file_get_contents($filePath);
        return unserialize($serializedObject);
    }

    /**
     * @param object  $object
     * @param array   $parameters
     *
     * @return bool
     * @throws \Exception
     */
    public function cacheObject($object, array $parameters) {
        $serializedObject = serialize($object);
        $reflection = new \ReflectionObject($object);
        $shortName = $reflection->getShortName();
        $parameterHash = $this->getParametersHash($parameters);
        $fileName = $this->getFileName($parameterHash);
        $directory = $this->getDirectory($shortName);
        $filePath = $this->getFilePath($directory,$fileName);
        if (!is_writable($this->directory)) {
            throw new \Exception("$directory must be writable to cache; check owner and permissions",500);
        }
        if (!file_exists($directory)) {
            mkdir($directory,0777);
        }
        if (file_exists($filePath)) {
            throw new \Exception("Trying to cache a file that is already cached; use 'isClassCached()' method first",500);
        }
        file_put_contents($filePath,$serializedObject);
        return true;
    }

    /**
     * @param string  $class
     * @param array   $parameters
     *
     * @return bool
     * @throws \Exception
     */
    public function isClassCached($class, array $parameters) {
        if (!class_exists($class)) {
            throw new \Exception("Invalid class name '$class'",500);
        }
        $reflection = new \ReflectionClass($class);
        $shortName = $reflection->getShortName();
        $parameterHash = $this->getParametersHash($parameters);
        $fileName = $this->getFileName($parameterHash);
        $directory = $this->getDirectory($shortName);
        $filePath = $this->getFilePath($directory,$fileName);
        if (!is_readable($this->directory)) {
            throw new \Exception("$directory must be readable to cache; check owner and permissions",500);
        }
        if (!file_exists($filePath)) {
            return false;
        }
        return true;
    }

    /**
     * @param array $parameters
     *
     * @return string
     */
    private function getParametersHash(array $parameters) {
        return md5(serialize($parameters));
    }

    /**
     * @param string $parameterHash
     *
     * @return string
     */
    private function getFileName($parameterHash) {
        return $parameterHash . "." . self::EXTENSION;
    }

    /**
     * @param string $shortName
     *
     * @return string
     */
    private function getDirectory($shortName) {
        return $this->directory . "/" . $shortName;
    }

    /**
     * @param string $directory
     * @param string $fileName
     *
     * @return string
     */
    private function getFilePath($directory, $fileName) {
        return $directory . "/" . $fileName;
    }
}