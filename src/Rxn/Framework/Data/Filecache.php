<?php

namespace Rxn\Framework\Data;

use \Rxn\Framework\Config;

class Filecache
{
    const EXTENSION = 'filecache';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var string
     */
    private $directory;

    /**
     * Filecache constructor.
     *
     * @param Config $config
     *
     * @throws \Exception
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->setDirectory();
    }

    /**
     * @throws \Exception
     */
    private function setDirectory()
    {
        $directory = __DIR__ . "/" . $this->config->fileCacheDirectory;
        if (!file_exists($directory)) {
            throw new \Exception("Cache $directory doesn't exist; it may need to be created", 500);
        }
        $this->directory = realpath(__DIR__ . "/" . $this->config->fileCacheDirectory);
    }

    /**
     * @param string $class
     * @param array  $parameters
     *
     * @return bool|mixed
     * @throws \Exception
     */
    public function getObject($class, array $parameters)
    {
        if (!class_exists($class)) {
            throw new \Exception("Invalid class name '$class'", 500);
        }
        $reflection    = new \ReflectionClass($class);
        $shortName     = $reflection->getShortName();
        $parameterHash = $this->getParametersHash($parameters);
        $fileName      = $this->getFileName($parameterHash);
        $directory     = $this->getDirectory($shortName);
        $filePath      = $this->getFilePath($directory, $fileName);
        if (!is_readable($this->directory)) {
            throw new \Exception("$directory must be readable to cache; check owner and permissions", 500);
        }
        if (!file_exists($filePath)) {
            return false;
        }
        $serializedObject = file_get_contents($filePath);
        return unserialize($serializedObject);
    }

    /**
     * @param object $object
     * @param array  $parameters
     *
     * @return bool
     * @throws \Exception
     */
    public function cacheObject($object, array $parameters)
    {
        $serializedObject = serialize($object);
        $reflection       = new \ReflectionObject($object);
        $shortName        = $reflection->getShortName();
        $parameterHash    = $this->getParametersHash($parameters);
        $fileName         = $this->getFileName($parameterHash);
        $directory        = $this->getDirectory($shortName);
        $filePath         = $this->getFilePath($directory, $fileName);
        if (!is_writable($this->directory)) {
            throw new \Exception("$directory must be writable to cache; check owner and permissions", 500);
        }
        if (!file_exists($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \Exception("Failed to create cache directory '$directory'", 500);
        }
        // Write to a temp file in the same directory, then atomically
        // rename into place so readers never see a half-written file
        // and concurrent writers cannot corrupt each other.
        $tempPath = tempnam($directory, 'fc_');
        if ($tempPath === false) {
            throw new \Exception("Failed to allocate temp file in '$directory'", 500);
        }
        if (file_put_contents($tempPath, $serializedObject, LOCK_EX) === false) {
            @unlink($tempPath);
            throw new \Exception("Failed to write cache temp file '$tempPath'", 500);
        }
        if (!rename($tempPath, $filePath)) {
            @unlink($tempPath);
            throw new \Exception("Failed to move cache file into place at '$filePath'", 500);
        }
        return true;
    }

    /**
     * @param string $class
     * @param array  $parameters
     *
     * @return bool
     * @throws \Exception
     */
    public function isClassCached($class, array $parameters)
    {
        if (!class_exists($class)) {
            throw new \Exception("Invalid class name '$class'", 500);
        }
        $reflection    = new \ReflectionClass($class);
        $shortName     = $reflection->getShortName();
        $parameterHash = $this->getParametersHash($parameters);
        $fileName      = $this->getFileName($parameterHash);
        $directory     = $this->getDirectory($shortName);
        $filePath      = $this->getFilePath($directory, $fileName);
        if (!is_readable($this->directory)) {
            throw new \Exception("$directory must be readable to cache; check owner and permissions", 500);
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
    private function getParametersHash(array $parameters)
    {
        return md5(serialize($parameters));
    }

    /**
     * @param string $parameterHash
     *
     * @return string
     */
    private function getFileName($parameterHash)
    {
        return $parameterHash . "." . self::EXTENSION;
    }

    /**
     * @param string $shortName
     *
     * @return string
     */
    private function getDirectory($shortName)
    {
        return $this->directory . "/" . $shortName;
    }

    /**
     * @param string $directory
     * @param string $fileName
     *
     * @return string
     */
    private function getFilePath($directory, $fileName)
    {
        return $directory . "/" . $fileName;
    }
}
