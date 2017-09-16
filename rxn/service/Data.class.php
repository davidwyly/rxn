<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Service;

use \Rxn\Service;
use \Rxn\Data\Database;

/**
 * Class Data
 *
 * @package Rxn\Service
 */
class Data
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
     * @param Service  $service
     *
     * @throws \Exception
     */
    public function __construct(Registry $registry, Database $database, Service $service)
    {
        //currenly disabled
        //$this->filecache = $service->get(Filecache::class);
        //$this->map       = $service->get(Map::class);
    }
}