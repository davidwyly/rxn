<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

class Datasources extends ApplicationDatasources {

    public $defaultRead  = 'read-only';

    public $defaultWrite = 'read-write';

    public $defaultAdmin = 'admin';

    public $databases = [
        'read-only' => [
            'host'     => 'localhost',
            'name'     => 'waretrax',
            'username' => 'dbuser',
            'password' => '123',
            'charset'  => 'utf8',
        ],
        'read-write' => [
            'host'     => 'localhost',
            'name'     => 'waretrax',
            'username' => 'dbuser',
            'password' => '123',
            'charset'  => 'utf8',
        ],
        'admin' => [
            'host'     => 'localhost',
            'name'     => 'waretrax',
            'username' => 'root',
            'password' => '123',
            'charset'  => 'utf8',
        ],
    ];
}