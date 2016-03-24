<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Service;

use \Rxn\Service;
use \Rxn\Data\Database;
use \Rxn\Data\Filecache;
use \Rxn\Data\Map;
use \Rxn\Data\Chain;
use \Rxn\Data\Mold;

class Data
{
    public $map;
    public $chain;
    public $mold;

    public function __construct(Registry $registry, Database $database, Service $service)
    {
        $this->filecache = $service->get(Filecache::class);
        $this->map = $service->get(Map::class);
        //$this->cache->objectPattern('\\Rxn\\Data\\Map',[Database::getName()]);
        //$this->chain = new Chain($this->map);
        //$this->mold = new Mold($this->map);
    }
}