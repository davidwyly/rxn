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

namespace Rxn\Data\Map;

use \Rxn\Service;
use \Rxn\Data\Database;
use \Rxn\Service\Registry;

class Table extends Service
{
    /**
     * @var
     */
    public $name;
    public $table_info;
    public $column_info      = [];
    public $primary_keys     = [];
    public $field_references = [];
    public $cacheTime        = null;
    //protected $fromCache = false;

    /**
     * Table constructor.
     *
     * @param Registry $registry
     * @param Database $database
     * @param string   $table_name
     * @param bool     $create_reference_maps
     *
     * @throws \Exception
     */
    public function __construct(
        Registry $registry,
        Database $database,
        string $table_name,
        bool $create_reference_maps = true
    ) {
        $this->validateTableExists($database, $registry, $table_name);
        $construct_params = [
            $registry,
            $database,
            $table_name,
            $create_reference_maps,
        ];
        $this->initialize($construct_params);
    }

    /**
     * @param Database $database
     * @param Registry $registry
     * @param          $table_name
     *
     * @throws \Exception
     */
    private function validateTableExists(Database $database, Registry $registry, string $table_name)
    {
        if (!$this->tableExists($database, $registry, $table_name)) {
            throw new \Exception(__METHOD__ . " returned false: table '$table_name' doesn't exist", 500);
        }
    }

    /**
     * @param array $construct_params
     *
     * @throws \Exception
     */
    public function initialize(array $construct_params)
    {
        $this->initializeNormally($construct_params);
    }

    /**
     * @param array $construct_params {[Registry $registry, Database $database, string $table_name, bool
     *                                $create_reference_maps]}
     *
     * @throws \Exception
     */
    public function initializeNormally(array $construct_params)
    {
        // reverse engineer the parameters
        list($registry, $database, $table_name, $create_reference_maps) = $construct_params;

        // generate the table map
        $this->name = $table_name;
        $this->generateTableMap($database, $table_name, $create_reference_maps);
    }

    /**
     * @param Database $database
     * @param string   $table_name
     * @param bool     $create_reference_maps
     *
     * @return bool
     * @throws \Exception
     */
    private function generateTableMap(Database $database, string $table_name, bool $create_reference_maps): bool
    {
        $this->table_info   = $this->getTableInfo($database, $table_name);
        $this->column_info  = $this->getColumns($database, $table_name);
        $this->primary_keys = $this->getPrimaryKeys($database, $table_name);
        if ($create_reference_maps) {
            $this->createReferenceMaps();
        }
        return true;
    }

    /**
     * @return void
     */
    private function createReferenceMaps(): void
    {
        if (is_array($this->column_info)) {
            foreach ($this->column_info as $key => $value) {
                if (isset($value['referenced_table_schema'])
                    && isset($value['referenced_table_name'])
                ) {
                    $reference_database = $value['referenced_table_schema'];
                    $reference_table    = $value['referenced_table_name'];
                    if ($reference_table) {
                        $this->field_references[$key] = [
                            'schema' => $reference_database,
                            'table'  => $reference_table,
                        ];
                    }
                }
            }
        }
    }

    /**
     * @param Database $database
     * @param          $table_name
     *
     * @return array|mixed
     * @throws \Exception
     */
    protected function getTableDetails(Database $database, string $table_name)
    {
        $database_name = $database->getName();
        $SQL           = /** @lang MySQL */
            "
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
            FROM information_schema.columns AS c
            LEFT JOIN information_schema.key_column_usage AS kcu
                ON kcu.column_name = c.column_name
                    AND kcu.referenced_table_schema LIKE :database_name
                    AND kcu.referenced_table_name IS NOT NULL
            WHERE c.table_schema LIKE :database_name
                AND c.table_name LIKE :table_name
            GROUP BY c.column_name
            ORDER BY c.ordinal_position ASC
        ";
        $bindings      = [
            'database_name' => $database_name,
            'table_name'    => $table_name,
        ];
        $result        = $database->fetchAll($SQL, $bindings, true, 1);
        return $result;
    }

    /**
     * @param Database $database
     * @param Registry $registry
     * @param string   $table_name
     *
     * @return bool
     * @throws \Exception
     */
    public function tableExists(Database $database, Registry $registry, string $table_name): bool
    {
        $database_name = $database->getName();
        if (in_array($table_name, $registry->tables[$database_name])) {
            return true;
        }
        $SQL = /** @lang MySQL */
            "
			SELECT
				table_name
			FROM information_schema.tables AS t
			WHERE t.table_schema LIKE ?
				AND t.table_name LIKE ?
				AND t.table_type = 'BASE TABLE'
			";
        if ($database->fetch($SQL, [$database_name, $table_name], true, 1)) {
            return true;
        }
        return false;
    }

    /**
     * @param Database $database
     * @param          $table_name
     *
     * @return array|mixed
     * @throws \Exception
     */
    private function getTableInfo(Database $database, string $table_name)
    {
        $database_name = $database->getName();
        $SQL           = /** @lang MySQL */
            "
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
        $result        = $database->fetch($SQL, [$database_name, $table_name], true, 1);
        return $result;
    }

    /**
     * @param Database $database
     * @param          $table_name
     *
     * @return array|mixed
     * @throws \Exception
     */
    private function getPrimaryKeys(Database $database, string $table_name)
    {
        $database_name = $database->getName();
        $SQL           = /** @lang MySQL */
            "
            SELECT COLUMN_NAME
            FROM information_schema.key_column_usage AS kcu
            WHERE kcu.table_schema LIKE ?
                AND kcu.table_name LIKE ?
                AND kcu.constraint_name LIKE 'PRIMARY'
        ";
        $result        = $database->fetchArray($SQL, [$database_name, $table_name], true, 1);
        return $result;
    }

    /**
     * @param Database $database
     * @param          $table_name
     *
     * @return array|mixed
     * @throws \Exception
     */
    private function getColumns(Database $database, string $table_name)
    {
        $result = $this->getTableDetails($database, $table_name);
        if (!$result) {
            throw new \Exception(__METHOD__ . " returned false", 500);
        }
        foreach ($result as $key => $value) {
            $currentColumn          = $value['column_name'];
            $result[$currentColumn] = $value;
            unset($result[$key]);
        }
        return $result;
    }
}
