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

namespace Rxn\Data;

use \Rxn\Service\Registry;
use \Rxn\Data\Map\Table;

class Map
{
    /**
     * @var string
     */
    public $fingerprint;

    /**
     * @var Table[]
     */
    public $tables;

    /**
     * @var
     */
    public $chain;

    /**
     * Map constructor.
     *
     * @param Registry  $registry
     * @param Database  $database
     * @param Filecache $filecache
     *
     * @throws \Exception
     */
    public function __construct(Registry $registry, Database $database, Filecache $filecache)
    {
        $this->validateRegistry($registry);
        $this->generateTableMaps($registry, $database, $filecache);
        $this->fingerprint = $this->generateFingerprint();
    }

    /**
     * @param Registry  $registry
     * @param Database  $database
     * @param Filecache $filecache
     *
     * @return bool
     * @throws \Exception
     */
    private function generateTableMaps(Registry $registry, Database $database, Filecache $filecache)
    {
        $database_name = $database->getName();
        if (!isset($registry->tables[$database_name])) {
            return false;
        }
        foreach ($registry->tables[$database_name] as $table_name) {
            $is_cached = $filecache->isClassCached(Table::class, [$database_name, $table_name]);
            if ($is_cached === true) {
                $table = $filecache->getObject(Table::class, [$database_name, $table_name]);
            } else {
                $table = $this->createTable($registry, $database, $table_name);
                $filecache->cacheObject($table, [$database_name, $table_name]);
            }
            $this->registerTable($table);
        }
        ksort($this->tables);
        return true;
    }

    /**
     * @param Registry $registry
     * @param Database $database
     * @param          $table_name
     *
     * @return Table
     */
    protected function createTable(Registry $registry, Database $database, string $table_name)
    {
        return new Table($registry, $database, $table_name);
    }

    /**
     * @param Table $table
     */
    public function registerTable(Table $table)
    {
        $table_name                = $table->name;
        $this->tables[$table_name] = $table;
    }

    /**
     * @param Registry $registry
     *
     * @throws \Exception
     */
    private function validateRegistry(Registry $registry)
    {
        if (empty($registry->tables)) {
            throw new \Exception("Cannot find any registered database tables", 500);
        }
    }

    /**
     * @return string
     */
    private function generateFingerprint()
    {
        return md5(json_encode($this));
    }

}