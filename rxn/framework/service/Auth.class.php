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

namespace Rxn\Framework\Service;

use \Rxn\Framework\Config;
use \Rxn\Framework\Service;
use \Rxn\Framework\Auth\Key;
use \Rxn\Framework\Container;

class Auth extends Service
{
    /**
     * @var Key
     */
    public $key;

    /**
     * Auth constructor.
     *
     * @param Config  $config
     * @param Container $container
     *
     * @throws \Exception
     */
    public function __construct(Config $config, Container $container)
    {
        $this->key = $container->get(Key::class);
    }
}
