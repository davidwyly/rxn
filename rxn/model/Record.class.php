<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
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

    protected $table;
    private   $primaryKey;
    private   $autoIncrement;

    /**
     * Record constructor.
     *
     * @param Registry $registry
     * @param Database $database
     * @param Map      $map
     *
     * @throws \Exception
     */
    public function __construct(Registry $registry, Database $database, Map $map)
    {
        $this->validateTableProperty($this->table);
        $this->validateTableName($database, $registry, $this->table);
        $table      = $this->getTable($map, $this->table);
        $primaryKey = $this->getPrimaryKey($table);
        $this->setPrimaryKey($primaryKey);
        $this->setColumns($table);
        $this->setRequiredColumns($table);
    }


    private function validateTableProperty($table)
    {
        if (empty($table)) {
            throw new \Exception("Required property 'table' for '" . __CLASS__ . "' is not defined", 500);
        }
    }

    /**
     * @param Database $database
     * @param array    $keyValues
     *
     * @return mixed
     * @throws \Exception
     */
    public function create(Database $database, array $keyValues)
    {
        $this->validateRequiredColumns($keyValues);

        // disallow explicit specification of the primary key
        $primaryKey = $this->primaryKey;
        if (isset($keyValues[$primaryKey])) {
            throw new \Exception("'$primaryKey' is not allowed in the create request; it is a primary key that will be auto-generated'",
                400);
        }

        // disallow empty records
        if (empty($keyValues)) {
            throw new \Exception("Cannot create an empty record", 400);
        }

        // create the key and value arrays for future SQL generation
        $keys     = [];
        $bindings = [];
        foreach ($keyValues as $key => $value) {
            $keys[]         = "`$key`";
            $bindings[$key] = "$value";
        }

        // build the SQL strings
        $columns      = implode(",", $keys);
        $placeholders = [];
        foreach ($bindings as $key => $value) {
            $placeholders[] = ":$key";
        }
        $placeholderValues = implode(",", $placeholders);

        $table = $this->table;

        // generate the SQL statement
        $createSql = "INSERT INTO $table ($columns) VALUES ($placeholderValues)";
        $database->transactionOpen();
        $result = $database->query($createSql, $bindings);
        if (!$result) {
            throw new \Exception("Failed to create record on database '{$database->getName()}'", 500);
        }
        $createdId = $database->getLastInsertId();
        $database->transactionClose();
        return $createdId;
    }

    public function read(Database $database, $id)
    {
        $primaryKey = $this->primaryKey;
        $table      = $this->table;
        $readSql    = "SELECT * FROM $table WHERE $primaryKey = :id";
        $result     = $database->fetch($readSql, ['id' => $id]);
        if (!$result) {
            throw new \Exception("Failed to find record '$id' on database '{$database->getName()}'", 404);
        }
        return $result;
    }

    public function update(Database $database, $id, array $keyValues)
    {
        $primaryKey = $this->primaryKey;
        $table      = $this->table;

        $expressions = [];
        foreach ($keyValues as $key => $value) {
            $expressions[] = "$key=:$key";
        }

        $expressionList = implode(",", $expressions);

        // append primary key onto the key value pairs for manual binding
        $keyValues = $keyValues + ['id' => $id];

        // generate SQL
        $updateSql = "UPDATE $table SET $expressionList WHERE $primaryKey=:id";

        // update record
        $result = $database->query($updateSql, $keyValues);
        if (!$result) {
            throw new \Exception("Failed to update record '$id' on database '{$database->getName()}'", 500);
        }
        return $id;
    }

    public function delete(Database $database, $id, $softDelete = false)
    {

        if ($softDelete) {
            //TODO
        }

        $primaryKey = $this->primaryKey;
        $table      = $this->table;

        $deleteSql = "DELETE FROM $table WHERE $primaryKey=:id";
        $database->transactionOpen();
        $result = $database->query($deleteSql, ['id' => $id]);
        if (!$result) {
            throw new \Exception("Failed to delete record '$id' on database '{$database->getName()}'", 500);
        }
        $lastAffectedRows = $database->getLastAffectedRows();
        if (empty($lastAffectedRows)) {
            throw new \Exception("Failed to find record '$id' on database '{$database->getName()}'", 404);
        }
        $database->transactionClose();
        return $id;
    }

    /**
     * @param array $keyValues
     *
     * @throws \Exception
     */
    protected function validateRequiredColumns(array $keyValues)
    {
        $requiredColumns = $this->getRequiredColumns();
        foreach ($requiredColumns as $requiredColumn) {
            if (!isset($keyValues[$requiredColumn])) {
                throw new \Exception("Key '$requiredColumn' is required", 500);
            }
        }
    }

    /**
     * @return mixed
     */
    public function getColumns()
    {
        return $this->_columns;
    }

    /**
     * @return mixed
     */
    public function getRequiredColumns()
    {
        return $this->_requiredColumns;
    }

    /**
     * @param Table $table
     */
    protected function setColumns(Table $table)
    {

        $columns = [];
        foreach ($table->columnInfo as $columnName => $columnData) {
            $columns[$columnName] = $columnData['column_type'];
        }
        $this->_columns = $columns;
    }

    /**
     * @param Table $table
     */
    protected function setRequiredColumns(Table $table)
    {

        $requiredColumns = [];
        foreach ($table->columnInfo as $columnName => $columnData) {

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
    protected function getTable(Map $map, $tableName)
    {
        return $map->tables[$tableName];
    }

    /**
     * @param $primaryKey
     */
    protected function setPrimaryKey($primaryKey)
    {
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
    protected function validateTableName(Database $database, Registry $registry, $tableName)
    {
        $databaseName = $database->getName();

        // validate that tables exist for the database
        if (!isset($registry->tables[$databaseName])
            || empty($registry->tables[$databaseName])
        ) {
            throw new \Exception("No tables found in database '$databaseName'", 500);
        }

        // validate that the relevant table exists in the database
        $relevantTables = $registry->tables[$databaseName];
        if (in_array($tableName, $relevantTables)) {
            return true;
        } else {
            $reflection = new \ReflectionObject($this);
            $recordName = $reflection->getName();
            throw new \Exception("Record '$recordName' references table '$tableName' which doesn't exist", 500);
        }
    }

    /**
     * @param Table $table
     *
     * @return string
     */
    protected function getPrimaryKey(Table $table)
    {
        return implode("-", $table->primaryKeys);
    }
}