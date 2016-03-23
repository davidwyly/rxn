<?php
/**
 *
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 *
 */

namespace Rxn\Service;

use \Rxn\Config;
use \Rxn\Auth\Key;
use \Rxn\Utility\Debug;

class Auth
{
    public $key;

    public function __construct(Config $config) {
        $this->key = new Key;
        $this->key->setEncryptionKey($config->applicationKey);
    }
}