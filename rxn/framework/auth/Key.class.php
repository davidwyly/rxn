<?php
/**
 * This file is part of the Rxn (Reaction) PHP API App
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Framework\Auth;

class Key
{
    static private $encryption_min_length = 32;
    private        $encryption_key;

    public function __construct()
    {
        //
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

        $encryption_key_length = mb_strlen($encryption_key);

        if ($encryption_key_length < $min_length) {
            throw new \Exception("Encryption key must be at least $min_length characters", 500);
        }
        $this->encryption_key = $encryption_key;
        return null;
    }
}
