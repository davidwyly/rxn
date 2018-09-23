<?php declare(strict_types=1);

namespace Rxn\Framework\Service;

use \Rxn\Framework\Config;
use \Rxn\Framework\Service as BaseService;
use \Rxn\Framework\Auth\Key;
use \Rxn\Framework\Container;

class Auth extends BaseService
{
    /**
     * @var Key
     */
    public $key;

    /**
     * Auth constructor.
     *
     * @param Container $container
     *
     * @throws \Exception
     */
    public function __construct(Container $container)
    {
        $this->key = $container->get(Key::class);
    }
}
