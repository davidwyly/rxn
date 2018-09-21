<?php

namespace Rxn\Framework;

final class Datasources extends BaseDatasources
{
    public $databases = [

        self::DEFAULT_READ => [
            'host'     => 'mysql',
            'name'     => 'bitrunr',
            'username' => 'root',
            'password' => 'docker',
            'charset'  => 'utf8',
        ],

        self::DEFAULT_WRITE => [
            'host'     => 'mysql',
            'name'     => 'bitrunr',
            'username' => 'root',
            'password' => 'docker',
            'charset'  => 'utf8',
        ],

        self::DEFAULT_ROOT => [
            'host'     => 'mysql',
            'name'     => 'bitrunr',
            'username' => 'root',
            'password' => 'docker',
            'charset'  => 'utf8',
        ],

        self::DEFAULT_CACHE => [
            'host'     => 'mysql',
            'name'     => 'bitrunr',
            'username' => 'root',
            'password' => 'docker',
            'charset'  => 'utf8',
        ],
    ];
}