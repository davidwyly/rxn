<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data\Map;

use \Rxn\Data\Map;
use \Rxn\Data\Database;
use \Rxn\Service\Registry;
use \Rxn\Utility\Debug;

/**
 * Class Table
 *
 * @package Rxn\Data\Map
 */
class Table
{
    public $name;
    public $tableInfo;
    public $columnInfo = array();
    public $primaryKeys = array();
    public $fieldReferences = array();
    public $cacheTime = null;
    //protected $fromCache = false;

    /**
     * Table constructor.
     *
     * @param Registry $registry
     * @param Database $database
     * @param          $tableName
     * @param bool     $createReferenceMaps
     */
    public function __construct(Registry $registry, Database $database, $tableName, $createReferenceMaps = true) {
        $this->validateTableExists($database,$registry,$tableName);
        $constructParams = [
            $registry,
            $database,
            $tableName,
            $createReferenceMaps,
        ];
        $this->initialize($constructParams);
    }

    /**
     * @param Database $database
     * @param Registry $registry
     * @param          $tableName
     *
     * @throws \Exception
     */
    private function validateTableExists(Database $database, Registry $registry, $tableName) {
        if (!$this->tableExists($database,$registry,$tableName)) {
            throw new \Exception(__METHOD__ . " returned false: table '$tableName' doesn't exist",500);
        }
    }

    /**
     * @param array $constructParams
     */
    public function initialize(array $constructParams) {
        $this->initializeNormally($constructParams);
    }

    /**
     * @param array $constructParams
     */
    public function initializeNormally(array $constructParams)
    {
        // reverse engineer the parameters
        list($registry, $database, $tableName, $createReferenceMaps) = $constructParams;

        // generate the table map
        $this->name = $tableName;
        $this->generateTableMap($database, $tableName, $createReferenceMaps);
    }

    /**
     * @param Database $database
     * @param          $tableName
     * @param          $createReferenceMaps
     *
     * @return bool
     * @throws \Exception
     */
    private function generateTableMap(Database $database, $tableName, $createReferenceMaps) {
        $this->tableInfo = $this->getTableInfo($database,$tableName);
        $this->columnInfo = $this->getColumns($database,$tableName);
        $this->primaryKeys = $this->getPrimaryKeys($database,$tableName);
        if ($createReferenceMaps) {
            $this->createReferenceMaps();
        }
        return true;
    }

    /**
     *
     */
    private function createReferenceMaps() {
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

    /**
     * @param Database $database
     * @param          $tableName
     *
     * @return array|mixed
     */
    protected function getTableDetails(Database $database, $tableName) {
        $databaseName = $database->getName();
        $SQL = /** @lang MySQL */ "
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
        $result = $database->fetchAll($SQL,$bindings,true,1);
        return $result;
    }

    /**
     * @param Database $database
     * @param Registry $registry
     * @param          $tableName
     *
     * @return bool
     */
    public function tableExists(Database $database, Registry $registry, $tableName) {
        $databaseName = $database->getName();
        if (in_array($tableName,$registry->tables[$databaseName])) {
            return true;
        }
        $SQL = /** @lang MySQL */ "
			SELECT
				table_name
			FROM information_schema.tables AS t
			WHERE t.table_schema LIKE ?
				AND t.table_name LIKE ?
				AND t.table_type = 'BASE TABLE'
			";
        if ($database->fetch($SQL,[$databaseName, $tableName],true,1)) {
            return true;
        }
        return false;
    }

    /**
     * @param Database $database
     * @param          $tableName
     *
     * @return array|mixed
     */
    private function getTableInfo(Database $database, $tableName) {
        $databaseName = $database->getName();
        $SQL = /** @lang MySQL */ "
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
        $result = $database->fetch($SQL,[$databaseName, $tableName],true,1);
        return $result;
    }

    /**
     * @param Database $database
     * @param          $tableName
     *
     * @return array|mixed
     */
    private function getPrimaryKeys(Database $database, $tableName) {
        $databaseName = $database->getName();
        $SQL = /** @lang MySQL */ "
            SELECT COLUMN_NAME
            FROM information_schema.key_column_usage AS kcu
            WHERE kcu.table_schema LIKE ?
                AND kcu.table_name LIKE ?
                AND kcu.constraint_name LIKE 'PRIMARY'
        ";
        $result =  $database->fetchArray($SQL,[$databaseName, $tableName],true,1);
        return $result;
    }

    /**
     * @param Database $database
     * @param          $tableName
     *
     * @return array|mixed
     * @throws \Exception
     */
    private function getColumns(Database $database, $tableName) {
        $result = $this->getTableDetails($database,$tableName);
        if (!$result) {
            throw new \Exception(__METHOD__ . " returned false",500);
        }
        foreach ($result as $key=>$value) {
            $currentColumn = $value['column_name'];
            $result[$currentColumn] = $value;
            unset($result[$key]);
        }
        return $result;
    }
}