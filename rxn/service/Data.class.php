<?php

namespace Rxn\Service;

use \Rxn\Data\Database;
use \Rxn\Data\Cache;
use \Rxn\Data\Map;
use \Rxn\Data\Chain;
use \Rxn\Data\Mold;

class Data
{
    public $database;
    public $map;
    public $chain;
    public $mold;

    public function __construct(Database $database)
    {
        $this->database = $database;
        //$this->cache = new Cache();
        //$this->map = new Map(Database::getName());
        //$this->cache->objectPattern('\\Rxn\\Data\\Map',[Database::getName()]);
        //$this->chain = new Chain($this->map);
        //$this->mold = new Mold($this->map);
    }
}