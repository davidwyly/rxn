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

class Filecache
{
    public $objectParameterHashes;

    public $directory;

    const EXTENSION = 'filecache';

    public function __construct(Config $config) {
        $this->setDirectory($config);
    }

    private function setDirectory(Config $config) {
        $this->directory = realpath(__DIR__ . "/" . $config->fileCacheDirectory);
    }

    public function getObject($class, array $parameters) {
        if (!class_exists($class)) {
            throw new \Exception("Invalid class name '$class'");
        }
        $reflection = new \ReflectionClass($class);
        $shortName = $reflection->getShortName();
        $parameterHash = $this->getParametersHash($parameters);
        $fileName = $this->getFileName($parameterHash);
        $directory = $this->getDirectory($shortName);
        $filePath = $this->getFilePath($directory,$fileName);
        if (!is_readable($this->directory)) {
            throw new \Exception("{$this->directory} must be readable to cache; check owner and permissions");
        }
        if (!file_exists($filePath)) {
            return false;
        }
        $serializedObject = file_get_contents($filePath);
        return unserialize($serializedObject);
    }

    public function cacheObject($object, array $parameters) {
        $serializedObject = serialize($object);
        $reflection = new \ReflectionObject($object);
        $shortName = $reflection->getShortName();
        $parameterHash = $this->getParametersHash($parameters);
        $fileName = $this->getFileName($parameterHash);
        $directory = $this->getDirectory($shortName);
        $filePath = $this->getFilePath($directory,$fileName);
        if (!is_writable($this->directory)) {
            throw new \Exception("{$this->directory} must be writable to cache; check owner and permissions");
        }
        if (!file_exists($directory)) {
            mkdir($directory,0777);
        }
        if (!file_exists($filePath)) {
            throw new \Exception("Trying to cache a file that is already cached; use 'isClassCached()' method first");
        }
        file_put_contents($filePath,$serializedObject);
        return true;
    }

    public function isClassCached($class, array $parameters) {
        if (!class_exists($class)) {
            throw new \Exception("Invalid class name '$class'");
        }
        $reflection = new \ReflectionClass($class);
        $shortName = $reflection->getShortName();
        $parameterHash = $this->getParametersHash($parameters);
        $fileName = $this->getFileName($parameterHash);
        $directory = $this->getDirectory($shortName);
        $filePath = $this->getFilePath($directory,$fileName);
        if (!is_readable($this->directory)) {
            throw new \Exception("{$this->directory} must be readable to cache; check owner and permissions");
        }
        if (!file_exists($filePath)) {
            return false;
        }
        return true;
    }

    private function getParametersHash(array $parameters) {
        return md5(serialize($parameters));
    }

    private function getFileName($parameterHash) {
        return $parameterHash . "." . self::EXTENSION;
    }

    private function getDirectory($shortName) {
        return $this->directory . "/" . $shortName;
    }

    private function getFilePath($directory, $fileName) {
        return $directory . "/" . $fileName;
    }
}