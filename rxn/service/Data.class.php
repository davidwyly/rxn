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
use \Rxn\Data\Database;
use \Rxn\Data\Filecache;
use \Rxn\Data\Map;

/**
 * TODO: work in progress
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