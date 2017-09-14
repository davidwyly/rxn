<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Service;

use \Rxn\Service;
use \Rxn\Config;
use \Rxn\Auth\Key;
use \Rxn\Utility\Debug;

class Auth
{
    /**
     * @var Key
     */
    public $key;

    /**
     * Auth constructor.
     *
     * @param Config  $config
     * @param Service $service
     *
     * @throws \Exception
     */
    public function __construct(Config $config, Service $service)
    {
        $this->key = $service->get(Key::class);
    }
}