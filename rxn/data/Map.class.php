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

namespace Rxn\Data;

use \Rxn\Service\Registry;

class Map
{
    protected $database;
    public $fingerprint;
    public $tables;
    public $chain;

    public function __construct($databaseName) {
        $this->database = $databaseName;
        $this->validateRegistry();
        $this->generateTableMaps();
        $this->fingerprint = $this->generateFingerprint();
    }

    private function generateTableMaps() {
        $databaseName = $this->database;
        if (!isset(Registry::$tables[$databaseName])) {
            throw new \Exception();
        }
        foreach (Registry::$tables[$databaseName] as $tableName) {
            $table = new Map\Table($databaseName,$tableName);
            $this->registerTable($table);
        }
        ksort($this->tables);
        return true;
    }

    public function registerTable(Map\Table $table) {
        $tableName = $table->name;
        $this->tables[$tableName] = $table;
    }

    private function validateRegistry() {
        if (empty(Registry::$tables)) {
            throw new \Exception("Expected registry to contain a list of database tables");
        }
    }


    private function generateFingerprint() {
        return md5(serialize($this));
    }

}