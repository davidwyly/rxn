<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Auth;

class Key
{
    static private $encryptionMinLength = 32;
    private $encryptionKey;
    private $encryptionMethod;

    public function __construct() {

    }

    /**
     * @param string $encryptionKey
     *
     * @return null
     * @throws \Exception
     */
    public function setEncryptionKey($encryptionKey) {
        $minLength = self::$encryptionMinLength;

        if (function_exists('mb_strlen')) {
            $encryptionKeyLength = mb_strlen($encryptionKey);
        } else {
            $encryptionKeyLength = strlen($encryptionKey);
        }

        if($encryptionKeyLength < $minLength) {
            throw new \Exception("Encryption key must be at least $minLength characters",500);
        }
        $this->encryptionKey = $encryptionKey;
        return null;
    }
}