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

namespace Rxn\Framework\Data;

class Mold
{
    protected $map;
    public    $tables;

    public function __construct(Map $map)
    {
        $this->map = $map->getFingerprint();
        $this->createReadContracts($map);
    }

    private function createReadContracts(Map $map)
    {
        foreach ($map->getTables() as $table_name => $table_map) {
            $column_info = $table_map->getColumnInfo();
            if (!empty($column_info)) {
                foreach ($column_info as $column => $info) {
                    $this->tables[$table_name][$column] = $this->getValidationType($info);
                }
            }
        }
    }

    private function isPrimary(array $column_info)
    {
        if ($column_info['column_key'] === 'PRI') {
            return true;
        }
        return false;
    }

    private function isRequired(array $column_info)
    {
        if ($column_info['is_nullable'] === 'NO') {
            return true;
        }
        return false;
    }

    private function isReference(array $column_info)
    {
        if (!empty($column_info['referenced_table_name'])) {
            return true;
        }
        return false;
    }

    private function getValidationType(array $column_info)
    {
        $column_type        = $column_info['column_type'];
        $column_type_simple = preg_replace('#\(.+#', '', $column_type);
        switch ($column_type_simple) {
            case 'varchar':
                $validationType = '[string]';
                break;
            case 'int':
                $validationType = '[int]';
                break;
            case 'decimal':
                $validationType = '[float]';
                break;
            case 'datetime':
                $validationType = '[date]';
                break;
            case 'tinyint':
                $validationType = '[bool]';
                break;
            default:
                $validationType = '[string]';
        }
        return $validationType;
    }
}
