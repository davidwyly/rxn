<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Framework;

class Datasources extends BaseDatasources
{
    public $databases = [

        self::DEFAULT_READ => [
            'host'     => 'localhost',
            'name'     => 'bitrunr',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8',
        ],

        self::DEFAULT_WRITE => [
            'host'     => 'localhost',
            'name'     => 'bitrunr',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8',
        ],

        self::DEFAULT_ADMIN => [
            'host'     => 'localhost',
            'name'     => 'bitrunr',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8',
        ],

        self::DEFAULT_CACHE => [
            'host'     => 'localhost',
            'name'     => 'bitrunr',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8',
        ],
    ];
}