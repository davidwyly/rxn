<?php

namespace Rxn\Auth;

class Key
{
    static private $encryptionMinLength = 32;
    private $encryptionKey;
    private $encryptionMethod;

    public function __construct() {

    }

    public function setEncryptionKey($encryptionKey) {
        $minLength = self::$encryptionMinLength;
        if(mb_strlen($encryptionKey) < $minLength) {
            throw new \Exception("Encryption key must be at least $minLength characters");
        }
        $this->encryptionKey = $encryptionKey;
        return null;
    }
}