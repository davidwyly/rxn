<?php

namespace Rxn\Service;

use \Rxn\Config;
use \Rxn\Auth\Key;

class Auth
{
    public $key;

    public function __construct() {
        $this->key = new Key;
        $this->key->setEncryptionKey(Config::$applicationKey);
    }

}