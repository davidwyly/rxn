<?php

namespace Rxn\Data\Map;

use \Rxn\Data\Map;
use \Rxn\Data\Database;
use \Rxn\Service\Registry;
use \Rxn\Utility\Debug;

class Table
{
    public $name;
    public $tableInfo;
    public $columnInfo = array();
    public $primaryKeys = array();
    public $fieldReferences = array();
    public $cacheTime = null;
    //protected $fromCache = false;

    public function __construct($databaseName, $tableName, $createReferenceMaps = true) {
        $constructParams = [
            $databaseName,
            $tableName,
            $createReferenceMaps,
        ];
        $this->initialize($constructParams);
    }

    public function initialize($constructParams) {
        $this->initializeNormally($constructParams);
    }

    public function initializeNormally(array $constructParams)
    {
        // reverse engineer the parameters
        $databaseName = $constructParams[0];
        $tableName = $constructParams[1];
        $createReferenceMaps = $constructParams[2];

        // generate the table map
        $this->name = $tableName;
        $this->generateTableMap($databaseName, $tableName, $createReferenceMaps);
    }

    protected function generateTableMap($databaseName, $tableName, $createReferenceMaps) {
        $this->tableInfo = self::getTableInfo($databaseName, $tableName);
        $this->columnInfo = self::getColumns($databaseName, $tableName);
        $this->primaryKeys = self::getPrimaryKeys($databaseName, $tableName);
        if ($createReferenceMaps) {
            $this->createReferenceMaps();
        }
        return true;
    }

    protected function createReferenceMaps() {
        if (is_array($this->columnInfo)) {
            foreach ($this->columnInfo as $key=>$value) {
                if (isset($value['referenced_table_schema'])
                    && isset($value['referenced_table_name'])) {
                    $referenceDatabase = $value['referenced_table_schema'];
                    $referenceTable = $value['referenced_table_name'];
                    if ($referenceTable) {
                        $this->fieldReferences[$key] = [
                            'schema' => $referenceDatabase,
                            'table' => $referenceTable,
                        ];
                    }
                }
            }
        }
    }

    static public function getTableDetails($databaseName, $tableName) {
        if (!self::tableExists($databaseName, $tableName)) {
            throw new \Exception(__METHOD__ . " returned false: table '$tableName' doesn't exist");
        }
        $SQL = "
				SELECT DISTINCT
					c.column_name,
					-- c.table_catalog,
					c.table_schema,
					c.table_name,
					-- c.ordinal_position,
					c.column_default,
					c.is_nullable,
					-- c.data_type,
					c.character_maximum_length,
					-- c.character_octet_length,
					-- c.numeric_precision,
					-- c.numeric_scale,
					-- c.datetime_precision,
					-- c.character_set_name,
					-- c.collation_name,
					c.column_type,
					c.column_key,
					c.extra,
					-- c.privileges,
					c.column_comment,
					-- kcu.constraint_catalog,
					kcu.constraint_schema,
					kcu.constraint_name,
					-- kcu.position_in_unique_constraint,
					kcu.referenced_table_schema,
					kcu.referenced_table_name,
					kcu.referenced_column_name
				FROM information_schema.columns as c
				LEFT JOIN information_schema.key_column_usage AS kcu
					ON kcu.column_name = c.column_name
						AND kcu.referenced_table_schema LIKE :databaseName
						AND kcu.referenced_table_name IS NOT NULL
				WHERE c.table_schema LIKE :databaseName
					AND c.table_name LIKE :tableName
				GROUP BY c.column_name
				ORDER BY c.ordinal_position ASC
			";
        $bindings = [
            'databaseName'=>$databaseName,
            'tableName'=>$tableName
        ];
        $result = Database::fetchAll($SQL,$bindings,true,1);
        return $result;
    }

    static public function tableExists($databaseName, $tableName) {
        if (in_array($tableName,Registry::$tables[$databaseName])) {
            return true;
        }
        $SQL = "
			SELECT
				table_name
			FROM information_schema.tables AS t
			WHERE t.table_schema LIKE ?
				AND t.table_name LIKE ?
				AND t.table_type = 'BASE TABLE'
			";
        if (Database::fetch($SQL,[$databaseName, $tableName],true,1)) {
            return true;
        }
        return false;
    }

    static public function getTableInfo($databaseName, $tableName) {
        if (!self::tableExists($databaseName, $tableName)) {
            throw new \Exception("Table '$tableName' doesn't exist");
        }
        $SQL = "
				SELECT
					t.table_catalog,
					t.table_schema,
					t.table_name,
					t.engine,
					t.version,
					t.table_rows,
					-- t.avg_row_length,
					-- t.data_length,
					-- t.max_data_length,
					-- t.index_length,
					-- t.data_free,
					t.auto_increment,
					t.create_time,
					t.update_time,
					t.check_time,
					t.table_collation,
					-- t.checksum,
					-- t.create_options,
					t.table_comment
				FROM information_schema.tables AS t
				WHERE t.table_schema LIKE ?
					AND t.table_name LIKE ?
			";
        $result = Database::fetch($SQL,[$databaseName, $tableName],true,1);
        return $result;
    }

    static public function getPrimaryKeys($databaseName, $tableName) {
        if (!self::tableExists($databaseName, $tableName)) {
            throw new \Exception(__METHOD__ . " returned false: table '$tableName' doesn't exist");
        }
        $SQL = "
				SELECT COLUMN_NAME
				FROM information_schema.key_column_usage AS kcu
				WHERE kcu.table_schema LIKE ?
					AND kcu.table_name LIKE ?
					AND kcu.constraint_name LIKE 'PRIMARY'
			";
        $result =  Database::fetchArray($SQL,[$databaseName, $tableName],true,1);
        return $result;
    }

    static public function getColumns($databaseName, $tableName) {
        $result = self::getTableDetails($databaseName, $tableName);
        if (!$result) {
            throw new \Exception(__METHOD__ . " returned false");
        }
        foreach ($result as $key=>$value) {
            $currentColumn = $value['column_name'];
            $result[$currentColumn] = $value;
            unset($result[$key]);
        }
        return $result;
    }
}