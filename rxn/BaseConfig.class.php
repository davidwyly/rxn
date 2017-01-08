<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

/**
 * Class BaseConfig
 * @package Rxn
 */
abstract class BaseConfig
{
    /**
     * Do not edit; this is set by the constructor
     *
     * @var string
     */
    public $root;

    /**
     * Defines the root of the application and vendor folders relative to this file
     *
     * @var string
     */
    public $relativeRoot = "/../";

    /**
     * Defines default services to run on startup
     * Note: Changing this has not been thoroughly tested!
     *
     * @var array
     */
    public $useServices = [
        'api'       => '\\Rxn\\Service\\Api',
        'auth'      => '\\Rxn\\Service\\Auth',
        'data'      => '\\Rxn\\Service\\Data',
        'model'     => '\\Rxn\\Service\\Model',
        'router'    => '\\Rxn\\Service\\Router',
        'stats'     => '\\Rxn\\Service\\Stats',
        'utility'   => '\\Rxn\\Service\\Utility',
        'test'      => '\\Rxn\\Service\\Test',
    ];

    public function __construct() {
        $this->root = realpath(__DIR__ . $this->relativeRoot);
    }
}