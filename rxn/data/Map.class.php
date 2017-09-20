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

namespace Rxn\Data;

use \Rxn\Service;
use \Rxn\Container;
use \Rxn\Service\Registry;
use \Rxn\Data\Map\Table;

class Map extends Service
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var Database
     */
    private $database;

    /**
     * @var Filecache
     */
    private $filecache;

    /**
     * @var string
     */
    private $fingerprint;

    /**
     * @var Table[]
     */
    private $tables;

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
        /**
         * assign dependencies
         */
        $this->registry  = $registry;
        $this->database  = $database;
        $this->filecache = $filecache;

        $this->validateRegistry();
        $this->generateTableMaps();
        $this->fingerprint = $this->generateFingerprint();
    }

    /**
     * @return bool
     * @throws \Exception
     */
    private function generateTableMaps()
    {
        $database_name = $this->database->getName();
        if (!isset($this->registry->tables[$database_name])) {
            return false;
        }
        foreach ($this->registry->tables[$database_name] as $table_name) {
            $is_cached = $this->filecache->isClassCached(Table::class, [$database_name, $table_name]);

            if ($is_cached === true) {
                $table = $this->filecache->getObject(Table::class, [$database_name, $table_name]);
                $this->registerTable($table);
                continue;
            }

            $table = $this->createTable($this->database);
            $this->filecache->cacheObject($table, [$database_name, $table_name]);
            $this->registerTable($table);

        }
        ksort($this->tables);
        return true;
    }

    /**
     * @param string $table_name
     *
     * @return Table
     * @throws \Rxn\Error\ContainerException
     */
    protected function createTable(string $table_name)
    {
        return new Table($this->registry, $this->database, $table_name);
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
     * @throws \Exception
     */
    private function validateRegistry()
    {
        if (empty($this->registry->tables)) {
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