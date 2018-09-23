<?php declare(strict_types=1);

namespace Rxn\Framework\Model;

use \Rxn\Framework\Data\Database;
use \Rxn\Framework\Data\Map;
use \Rxn\Framework\Data\Map\Table;
use \Rxn\Framework\Service\Registry;

abstract class Record extends Model
{
    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var Map
     */
    protected $map;

    protected $columns;
    protected $required_columns;

    /**
     * @var string
     */
    protected $table;
    private   $primary_key;

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
        /**
         * assign dependencies
         */
        $this->registry = $registry;
        $this->database = $database;
        $this->map      = $map;

        $this->validateTableProperty($this->table);
        $this->validateTableName($database, $registry, $this->table);
        $table      = $this->getTable($map, $this->table);
        $primaryKey = $this->getPrimaryKey($table);
        $this->setPrimaryKey($primaryKey);
        $this->setColumns($table);
        $this->setRequiredColumns($table);
    }


    /**
     * @param $table
     *
     * @throws \Exception
     */
    private function validateTableProperty($table)
    {
        if (empty($table)) {
            throw new \Exception("Required property 'table' for '" . __CLASS__ . "' is not defined", 500);
        }
    }

    /**
     * @param array $key_values
     *
     * @return mixed
     * @throws \Exception
     */
    public function create(array $key_values)
    {
        $this->validateRequiredColumns($key_values);

        // disallow explicit specification of the primary key
        if (isset($key_values[$this->primary_key])) {
            throw new \Exception("'$this->primary_key' is forbidden in create request as field is auto-generated'",
                400);
        }

        // disallow empty records
        if (empty($key_values)) {
            throw new \Exception("Cannot create an empty record", 400);
        }

        // create the key and value arrays for future SQL generation
        $keys     = [];
        $bindings = [];
        foreach ($key_values as $key => $value) {
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

        // generate the SQL statement
        $createSql = "
            INSERT INTO {$this->table} (
                $columns
            ) VALUES (
                $placeholderValues
            )
        ";
        $this->database->transactionOpen();
        $query  = $this->database->createQuery('query', $createSql, $bindings);
        $result = $query->run();
        if (!$result) {
            throw new \Exception("Failed to create record on database '{$this->database->getName()}'", 500);
        }
        $createdId = $this->database->getLastInsertId();
        $this->database->transactionClose();
        return $createdId;
    }

    public function read($record_id)
    {
        $readSql = "
            SELECT * 
            FROM {$this->table} 
            WHERE {$this->primary_key} = :id
        ";

        $result = $this->database->fetch($readSql, ['id' => $record_id]);
        if (!$result) {
            throw new \Exception("Failed to find record '$record_id' on database '{$this->database->getName()}'", 404);
        }
        return $result;
    }

    public function update($record_id, array $key_values)
    {
        $keys        = array_keys($key_values);
        $expressions = [];
        foreach ($keys as $key) {
            $expressions[] = "$key=:$key";
        }

        $expression_list = implode(",", $expressions);

        // append primary key onto the key value pairs for manual binding
        $key_values = $key_values + ['id' => $record_id];

        // generate SQL
        $update_sql = "
            UPDATE {$this->table} 
            SET $expression_list 
            WHERE {$this->primary_key}=:id
        ";

        // update record
        $result = $this->database->createQuery('query', $update_sql, $key_values);
        if (!$result) {
            throw new \Exception("Failed to update record '$record_id' on database '{$this->database->getName()}'",
                500);
        }
        return $record_id;
    }

    public function delete($record_id)
    {
        $primary_key = $this->primary_key;
        $table       = $this->table;

        $delete_sql = "
            DELETE FROM $table 
            WHERE $primary_key=:id
        ";
        $this->database->transactionOpen();
        $result = $this->database->query($delete_sql, ['id' => $record_id]);
        if (!$result) {
            throw new \Exception("Failed to delete record '$record_id'", 500);
        }
        $lastAffectedRows = $this->database->getLastAffectedRows();
        if (empty($lastAffectedRows)) {
            throw new \Exception("Failed to find record '$record_id'", 404);
        }
        $this->database->transactionClose();
        return $record_id;
    }

    /**
     * @param array $key_values
     *
     * @throws \Exception
     */
    protected function validateRequiredColumns(array $key_values)
    {
        $required_columns = $this->getRequiredColumns();
        foreach ($required_columns as $required_column) {
            if (!isset($key_values[$required_column])) {
                throw new \Exception("Key '$required_column' is required", 500);
            }
        }
    }

    /**
     * @return mixed
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * @return mixed
     */
    public function getRequiredColumns()
    {
        return $this->required_columns;
    }

    /**
     * @param Table $table
     */
    protected function setColumns(Table $table)
    {

        $columns = [];
        foreach ($table->getColumnInfo() as $column_name => $column_data) {
            $columns[$column_name] = $column_data['column_type'];
        }
        $this->columns = $columns;
    }

    /**
     * @param Table $table
     */
    protected function setRequiredColumns(Table $table)
    {

        $required_columns = [];
        foreach ($table->getColumnInfo() as $column_name => $column_data) {

            if ($column_data['is_nullable'] != "NO" || $column_data['column_key'] == 'PRI') {
                continue;
            }
            $required_columns[] = $column_name;
        }
        $this->required_columns = $required_columns;
    }

    /**
     * @param Map $map
     * @param     $table_name
     *
     * @return mixed
     * @throws \Exception
     */
    protected function getTable(Map $map, $table_name)
    {
        return $map->getTable($table_name);
    }

    /**
     * @param $primary_key
     */
    protected function setPrimaryKey($primary_key)
    {
        $this->primary_key = $primary_key;
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
        if (!in_array($tableName, $relevantTables)) {
            $reflection = new \ReflectionObject($this);
            $recordName = $reflection->getName();
            throw new \Exception("Record '$recordName' references table '$tableName' which doesn't exist", 500);
        }

        return true;
    }

    /**
     * @param Table $table
     *
     * @return string
     */
    protected function getPrimaryKey(Table $table)
    {
        return implode("-", $table->getPrimaryKeys());
    }
}
