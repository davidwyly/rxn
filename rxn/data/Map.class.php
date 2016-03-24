<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Service\Registry;

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
     * @param Database $database
     */
    public function __construct(Registry $registry, Database $database) {
        $this->validateRegistry($registry);
        $this->generateTableMaps($registry,$database);
        $this->fingerprint = $this->generateFingerprint();
    }

    /**
     * @return bool
     * @throws \Exception
     */
    private function generateTableMaps(Registry $registry, Database $database) {
        $databaseName = $database->getName();
        if (!isset($registry->tables[$databaseName])) {
            throw new \Exception();
        }
        foreach ($registry->tables[$databaseName] as $tableName) {
            $table = $this->tableFactory($registry,$database,$tableName);
            $this->registerTable($table);
        }
        ksort($this->tables);
        return true;
    }

    /**
     * @param $tableName
     *
     * @return Map\Table
     */
    protected function tableFactory(Registry $registry, Database $database, $tableName) {
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
     * @throws \Exception
     */
    private function validateRegistry(Registry $registry) {
        if (empty($registry->tables)) {
            throw new \Exception("Expected registry to contain a list of database tables");
        }
    }

    /**
     * @return string
     */
    private function generateFingerprint() {
        return md5(json_encode($this));
    }

}