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

namespace Rxn\Model;

use \Rxn\Utility\Debug;
use \Rxn\Data\Database;
use \Rxn\Data\Map\Table;
use \Rxn\Service\Registry;

abstract class Record extends Model
{

    static public $table;
    static public $primaryKey;
    protected $_columns;
    protected $_requiredColumns;

    public function __construct() {
        $tableName = $this::$table;
        $this->validateTableName($tableName);
        $table = $this->getTable($tableName);
        $primaryKey = $this->getPrimaryKey($table);
        $this->setPrimaryKey($primaryKey);
        $this->setColumns($table);
        $this->setRequiredColumns($table);
    }

    public function create(array $keyValues) {
        $this->validateRequiredColumns($keyValues);

        // disallow explicit specification of the primary key
        $primaryKey = $this::$primaryKey;
        if (isset($keyValues[$primaryKey])) {
            unset($keyValues[$primaryKey]);
        }
        if (empty($keyValues)) {
            throw new \Exception("Cannot create an empty record",500);
        }

        // create the key and value arrays for future SQL generation
        $keys = array();
        $bindings = array();
        foreach ($keyValues as $key=>$value) {
            $keys[] = "`$key`";
            $bindings[$key] = "$value";
        }

        // build the SQL strings
        $columns = implode(",",$keys);
        $placeholders = array();
        foreach ($bindings as $key=>$value) {
            $placeholders[] = ":$key";
        }
        $values = implode(",",$placeholders);

        $table = $this::$table;

        // generate the SQL statement
        $createSql = "INSERT INTO $table ($columns) VALUES ($values)";
        Database::transactionOpen();
        $result = Database::query($createSql,$bindings);
        if (!$result) {
            throw new \Exception("Failed to create record",500);
        }
        return Database::getLastInsertId();
    }

    protected function validateRequiredColumns(array $keyValues) {
        $requiredColumns = $this->getRequiredColumns();
        foreach ($requiredColumns as $requiredColumn) {
            if (!isset($keyValues[$requiredColumn])) {
                throw new \Exception("Key '$requiredColumn' is required");
            }
        }
    }

    public function getColumns() {
        return $this->_columns;
    }

    public function getRequiredColumns() {
        return $this->_requiredColumns;
    }

    protected function setColumns(Table $table) {

        $columns = array();
        foreach ($table->columnInfo as $columnName=>$columnData) {
            $columns[$columnName] = $columnData['column_type'];
        }
        $this->_columns = $columns;
    }

    protected function setRequiredColumns(Table $table) {

        $requiredColumns = array();
        foreach ($table->columnInfo as $columnName=>$columnData) {

            if ($columnData['is_nullable'] != "NO" || $columnData['column_key'] == 'PRI') {
                continue;
            }
            $requiredColumns[] = $columnName;
        }
        $this->_requiredColumns = $requiredColumns;
    }

    protected function getTable($tableName) {
        $map = $this->getMap();
        return $map->tables[$tableName];
    }

    protected function setPrimaryKey($primaryKey) {
        $this::$primaryKey = $primaryKey;
    }

    protected function getMap() {
        return \Rxn\Service\Data::$map;
    }

    protected function validateTableName($tableName) {
        foreach (Registry::$tables as $schema=>$tables) {
            foreach ($tables as $key=>$table) {
                if ($this::$table == $table) {
                    return true;
                }
            }
        }
        $reflection = new \ReflectionObject($this);
        $recordName = $reflection->getName();
        throw new \Exception("Record '$recordName' references table '$tableName' which doesn't exist",500);
    }

    protected function getPrimaryKey(Table $table) {
        return implode("-",$table->primaryKeys);
    }
}