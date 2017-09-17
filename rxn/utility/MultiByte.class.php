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

namespace Rxn\Utility;

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
    static public function strpos($haystack, $needle, $offset = 0, $encoding = null)
    {
        if (function_exists('mb_strpos')) {
            if (!is_null($encoding)) {
                return mb_strpos($haystack, $needle, $offset, $encoding);
            }
            return mb_strpos($haystack, $needle, $offset);
        }
        return strpos($haystack, $needle, $offset);
    }

    /**
     * @param string      $haystack
     * @param string      $needle
     * @param int         $offset
     * @param null|string $encoding
     *
     * @return int|false
     */
    static public function stripos($haystack, $needle, $offset = 0, $encoding = null)
    {
        if (function_exists('mb_stripos')) {
            if (!is_null($encoding)) {
                return mb_stripos($haystack, $needle, $offset, $encoding);
            }
            return mb_stripos($haystack, $needle, $offset);
        }
        return stripos($haystack, $needle, $offset);
    }

    /**
     * @param string $str
     * @param null   $encoding
     *
     * @return string
     */
    static public function strtolower($str, $encoding = null)
    {
        if (function_exists('mb_strtolower')) {
            if (!is_null($encoding)) {
                return mb_strtolower($str,$encoding);
            }
            return mb_strtolower($str);
        }
        return strtolower($str);
    }

    /**
     * @param string   $str
     * @param int      $start
     * @param int|null $length
     * @param null     $encoding
     *
     * @return string|array|false
     */
    static public function substr($str, $start, $length = null, $encoding = null)
    {
        if (function_exists('mb_substr')) {
            if (!is_null($encoding)) {
                return mb_substr($str, $start, $length, $encoding);
            }
            return mb_substr($str, $start, $length);
        }
        return substr($str, $start, $length);

    }
}