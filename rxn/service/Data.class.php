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

use \Rxn\ApplicationService;
use \Rxn\Data\Map;
use \Rxn\Container;
use \Rxn\Data\Database;
use \Rxn\Data\Filecache;


/**
 * TODO: work in progress
 */
class Data extends ApplicationService
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