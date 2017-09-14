<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

/**
 * Class MultiByte
 *
 * @package Rxn
 */
abstract class MultiByte
{
    /**
     * @param string      $haystack
     * @param string      $needle
     * @param int         $offset
     * @param null|string $encoding
     *
     * @return int|false
     */
    static public function strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null)
    {
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle, $offset, $encoding);
        } else {
            return strpos($haystack, $needle, $offset);
        }
    }

    /**
     * @param string      $haystack
     * @param string      $needle
     * @param int         $offset
     * @param null|string $encoding
     *
     * @return int|false
     */
    static public function stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null)
    {
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, $offset, $encoding);
        } else {
            return stripos($haystack, $needle, $offset);
        }
    }
}