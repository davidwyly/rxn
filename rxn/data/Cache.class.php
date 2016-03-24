<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Utility\Debug;

class Cache
{
    public $objectParameterHashes;

    static public $dataDirectory;

    public function __construct() {
        //
    }

    private function getCachedFiles() {

    }

    public function isCached($objectReference, array $parameters) {
        $this->validateObjectReference($objectReference);
        if (!$this->objectReferenceParametersExists($objectReference, $parameters)) {
            return false;
        }
    }

    public function objectPattern($objectReference, array $parameters) {
        $this->validateObjectReference($objectReference);
        $parametersHash = $this->getHash($parameters);
        $this->objectParameterHashes[$objectReference][$parametersHash] = 'cached';
    }

    private function validateObjectReference($objectReference) {
        if (!class_exists($objectReference)) {
            throw new \Exception("Object reference '$objectReference' is not valid");
        }
    }

    private function objectReferenceExists($objectReference) {
        if (isset($this->objectParameterHashes[$objectReference])) {
            return true;
        }
        return false;
    }

    private function objectReferenceParametersExists($objectReference, array $parameters) {
        if (!$this->objectReferenceExists($objectReference)) {
            return false;
        }
        $parametersHash = $this->getHash($parameters);
        if (isset($this->objectParameterHashes[$objectReference][$parametersHash])) {
            return true;
        }
        return false;
    }

    private function getHash($var) {
        return md5(serialize($var));
    }
}