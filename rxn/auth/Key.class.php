<?php
/**
 * This file is part of the Rxn (Reaction) PHP API Framework
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Auth;

class Key
{
    static private $encryption_min_length = 32;
    private        $encryption_key;
    private        $encryption_method;

    public function __construct()
    {

    }

    /**
     * @param string $encryption_key
     *
     * @return null
     * @throws \Exception
     */
    public function setEncryptionKey($encryption_key)
    {
        $min_length = self::$encryption_min_length;

        if (function_exists('mb_strlen')) {
            $encryption_key_length = mb_strlen($encryption_key);
        } else {
            $encryption_key_length = strlen($encryption_key);
        }

        if ($encryption_key_length < $min_length) {
            throw new \Exception("Encryption key must be at least $min_length characters", 500);
        }
        $this->encryption_key = $encryption_key;
        return null;
    }
}