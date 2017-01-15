<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Service\Registry;
use \Rxn\Utility\Debug;

/**
 * Class Map
 *
 * @package Rxn\Data
 */
class Map
{
    /**
     * @var string
     */
    public $fingerprint;

    /**
     * @var
     */
    public $tables;

    /**
     * @var
     */
    public $chain;

    /**
     * Map constructor.
     *
     * @param Registry $registry
     * @param Database $database
     * @param Filecache $filecache
     * @throws \Exception
     */
    public function __construct(Registry $registry, Database $database, Filecache $filecache) {
        $this->validateRegistry($registry);
        $this->generateTableMaps($registry,$database,$filecache);
        $this->fingerprint = $this->generateFingerprint();
    }

    /**
     * @param Registry $registry
     * @param Database $database
     * @param Filecache $filecache
     * @return bool
     * @throws \Exception
     */
    private function generateTableMaps(Registry $registry, Database $database, Filecache $filecache) {
        $databaseName = $database->getName();
        if (!isset($registry->tables[$databaseName])) {
            return false;
        }
        foreach ($registry->tables[$databaseName] as $tableName) {
            $isCached = $filecache->isClassCached(Map\Table::class,[$databaseName,$tableName]);
            if ($isCached === true) {
                $table = $filecache->getObject(Map\Table::class,[$databaseName,$tableName]);
            } else {
                $table = $this->createTable($registry,$database,$tableName);
                $filecache->cacheObject($table,[$databaseName,$tableName]);
            }
            $this->registerTable($table);
        }
        ksort($this->tables);
        return true;
    }

    /**
     * @param Registry $registry
     * @param Database $database
     * @param $tableName
     * @return Map\Table
     */
    protected function createTable(Registry $registry, Database $database, $tableName) {
        return new Map\Table($registry,$database,$tableName);
    }

    /**
     * @param Map\Table $table
     */
    public function registerTable(Map\Table $table) {
        $tableName = $table->name;
        $this->tables[$tableName] = $table;
    }

    /**
     * @param Registry $registry
     * @throws \Exception
     */
    private function validateRegistry(Registry $registry) {
        if (empty($registry->tables)) {
            throw new \Exception("Cannot find any registered database tables",500);
        }
    }

    /**
     * @return string
     */
    private function generateFingerprint() {
        return md5(json_encode($this));
    }

}