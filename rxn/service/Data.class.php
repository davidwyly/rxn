<?php
/**
 *
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 *
 */

namespace Rxn\Service;

use \Rxn\Data\Database;
use \Rxn\Data\Cache;
use \Rxn\Data\Map;
use \Rxn\Data\Chain;
use \Rxn\Data\Mold;

class Data
{
    public $map;
    public $chain;
    public $mold;

    public function __construct(Registry $registry, Database $database)
    {
        $this->cache = new Cache();
        $this->map = new Map($registry, $database);
        //$this->cache->objectPattern('\\Rxn\\Data\\Map',[Database::getName()]);
        //$this->chain = new Chain($this->map);
        //$this->mold = new Mold($this->map);
    }
}