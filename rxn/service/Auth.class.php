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

namespace Rxn\Service;

use \Rxn\Service;
use \Rxn\Config;
use \Rxn\Auth\Key;

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