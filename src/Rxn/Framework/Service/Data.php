<?php declare(strict_types=1);

namespace Rxn\Framework\Service;

use \Rxn\Framework\Service as BaseService;
use \Rxn\Framework\Data\Map;
use \Rxn\Framework\Container;
use \Rxn\Framework\Data\Database;
use \Rxn\Framework\Data\Filecache;

/**
 * TODO: work in progress
 */
class Data extends BaseService
{
    /**
     * @var Filecache
     */
    public $filecache;

    /**
     * @var Map
     */
    public $map;

    /**
     * @var
     */
    public $chain;

    /**
     * @var
     */
    public $mold;

    /**
     * Data constructor.
     *
     * @param Registry $registry
     * @param Database $database
     * @param Container  $container
     *
     * @throws \Exception
     */
    public function __construct(Registry $registry, Database $database, Container $container)
    {
        //currenly disabled
        //$this->filecache = $container->get(Filecache::class);
        //$this->map       = $container->get(Map::class);
    }
}
