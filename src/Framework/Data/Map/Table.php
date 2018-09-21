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

namespace Rxn\Framework\Data\Map;

use \Rxn\Framework\Service;
use \Rxn\Framework\Data\Database;
use \Rxn\Framework\Service\Registry;

class Table extends Service
{
    /**
     * @var Registry
     */
    private $registry;
    private $database;
    private $table_name;
    private $table_info;
    private $column_info           = [];
    private $primary_keys          = [];
    private $field_references      = [];
    private $create_reference_maps = false;

    /**
     * Table constructor.
     *
     * @param Registry $registry
     * @param Database $database
     * @param string   $table_name
     *
     * @throws \Exception
     */
    public function __construct(Registry $registry, Database $database, string $table_name)
    {

        $this->registry   = $registry;
        $this->database   = $database;
        $this->table_name = $table_name;

        $this->validateTableExists();
        $this->initialize();
    }

    /**
     * @throws \Exception
     */
    private function validateTableExists()
    {
        if (!$this->tableExists()) {
            throw new \Exception(__METHOD__ . " returned false: table '$this->table_name' doesn't exist", 500);
        }
    }

    /**
     * @throws \Exception
     */
    public function initialize()
    {
        // generate the table map
        $this->generateTableMap();
    }

    /**
     * @return bool
     * @throws \Exception
     *
     */
    private function generateTableMap(): bool
    {
        $this->table_info   = $this->getTableInfo();
        $this->column_info  = $this->getColumns();
        $this->primary_keys = $this->assignPrimaryKeys();
        if ($this->create_reference_maps) {
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
     * @return array|mixed
     * @throws \Rxn\Framework\Error\QueryException
     */
    protected function getTableDetails()
    {
        $database_name = $this->database->getName();
        $sql           = /** @lang MySQL */
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

        $bindings = [
            'database_name' => $database_name,
            'table_name'    => $this->table_name,
        ];

        $result = $this->database->fetchAll($sql, $bindings);
        return $result;
    }

    /**
     * @return bool
     * @throws \Rxn\Framework\Error\QueryException
     */
    public function tableExists(): bool
    {
        $database_name = $this->database->getName();
        if (in_array($this->table_name, $this->registry->tables[$database_name])) {
            return true;
        }
        $sql = /** @lang MySQL */
            "
			SELECT
				table_name
			FROM information_schema.tables AS t
			WHERE t.table_schema LIKE ?
				AND t.table_name LIKE ?
				AND t.table_type = 'BASE TABLE'
			";
        if ($this->database->fetch($sql, [$database_name, $this->table_name])) {
            return true;
        }
        return false;
    }

    /**
     * @return mixed
     * @throws \Rxn\Framework\Error\QueryException
     */
    private function getTableInfo()
    {
        $database_name = $this->database->getName();

        $sql = /** @lang MySQL */
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

        $result = $this->database->fetch($sql, [$database_name, $this->table_name]);
        return $result;
    }

    /**
     * @return array|mixed
     * @throws \Rxn\Framework\Error\QueryException
     */
    private function assignPrimaryKeys()
    {
        $database_name = $this->database->getName();

        $sql = /** @lang MySQL */
            "
            SELECT COLUMN_NAME
            FROM information_schema.key_column_usage AS kcu
            WHERE kcu.table_schema LIKE ?
                AND kcu.table_name LIKE ?
                AND kcu.constraint_name LIKE 'PRIMARY'
        ";

        $result = $this->database->fetchArray($sql, [$database_name, $this->table_name]);
        return $result;
    }

    /**
     * @return array|mixed
     * @throws \Exception
     */
    private function getColumns()
    {
        $result = $this->getTableDetails();
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

    /**
     * @return array
     */
    public function getColumnInfo(): array
    {
        return $this->column_info;
    }

    /**
     * @return array
     */
    public function getPrimaryKeys(): array
    {
        return $this->primary_keys;
    }
}
