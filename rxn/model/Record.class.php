<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Model;

use \Rxn\Utility\Debug;
use \Rxn\Data\Database;
use \Rxn\Data\Map;
use \Rxn\Data\Map\Table;
use \Rxn\Service\Registry;

abstract class Record extends Model
{

    /**
     * @var Database
     */
    protected $database;
    protected $_columns;
    protected $_requiredColumns;

    public $table;
    public $primaryKey;

    /**
     * Record constructor.
     *
     * @param Registry $registry
     * @param Database $database
     * @param Map      $map
     */
    public function __construct(Registry $registry, Database $database, Map $map) {
        $tableName = $this->table;
        $this->validateTableName($database,$registry,$tableName);
        $table = $this->getTable($map,$tableName);
        $primaryKey = $this->getPrimaryKey($table);
        $this->setPrimaryKey($primaryKey);
        $this->setColumns($table);
        $this->setRequiredColumns($table);
    }

    /**
     * @param array $keyValues
     *
     * @return mixed
     * @throws \Exception
     */
    public function create(Database $database, array $keyValues) {
        $this->validateRequiredColumns($keyValues);

        // disallow explicit specification of the primary key
        $primaryKey = $this->primaryKey;
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

        $table = $this->table;

        // generate the SQL statement
        $createSql = "INSERT INTO $table ($columns) VALUES ($values)";
        $database->transactionOpen();
        $result = $database->query($createSql,$bindings);
        if (!$result) {
            throw new \Exception("Failed to create record",500);
        }
        $createdId = $database->getLastInsertId();
        $database->transactionClose();
        return $createdId;
    }

    /**
     * @param array $keyValues
     *
     * @throws \Exception
     */
    protected function validateRequiredColumns(array $keyValues) {
        $requiredColumns = $this->getRequiredColumns();
        foreach ($requiredColumns as $requiredColumn) {
            if (!isset($keyValues[$requiredColumn])) {
                throw new \Exception("Key '$requiredColumn' is required");
            }
        }
    }

    /**
     * @return mixed
     */
    public function getColumns() {
        return $this->_columns;
    }

    /**
     * @return mixed
     */
    public function getRequiredColumns() {
        return $this->_requiredColumns;
    }

    /**
     * @param Table $table
     */
    protected function setColumns(Table $table) {

        $columns = array();
        foreach ($table->columnInfo as $columnName=>$columnData) {
            $columns[$columnName] = $columnData['column_type'];
        }
        $this->_columns = $columns;
    }

    /**
     * @param Table $table
     */
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

    /**
     * @param Map $map
     * @param     $tableName
     *
     * @return mixed
     */
    protected function getTable(Map $map, $tableName) {
        return $map->tables[$tableName];
    }

    /**
     * @param $primaryKey
     */
    protected function setPrimaryKey($primaryKey) {
        $this->primaryKey = $primaryKey;
    }

    /**
     * @param Database $database
     * @param Registry $registry
     * @param          $tableName
     *
     * @return bool
     * @throws \Exception
     */
    protected function validateTableName(Database $database,Registry $registry, $tableName) {
        $databaseName = $database->getName();
        $relevantTables = $registry->tables[$databaseName];
        if (in_array($tableName,$relevantTables)) {
            return true;
        }
        $reflection = new \ReflectionObject($this);
        $recordName = $reflection->getName();
        throw new \Exception("Record '$recordName' references table '$tableName' which doesn't exist",500);
    }

    /**
     * @param Table $table
     *
     * @return string
     */
    protected function getPrimaryKey(Table $table) {
        return implode("-",$table->primaryKeys);
    }
}