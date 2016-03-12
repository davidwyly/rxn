<?php

namespace Rxn\Data;

use \Rxn\Service\Registry;
use \Rxn\Utility\Debug;

class Mold
{
    protected $map;
    public $tables;

    public function __construct(Map $map) {
        $this->map = $map->fingerprint;
        $this->createReadContracts($map);
    }

    private function createReadContracts(Map $map) {
        foreach ($map->tables as $tableName=>$tableMap) {
            if (isset($tableMap->columnInfo)) {
                foreach ($tableMap->columnInfo as $column=>$columnInfo) {
                    $this->tables[$tableName][$column] = $this->getValidationType($columnInfo);
                }
            }
        }
    }

    private function isPrimary(array $columnInfo) {
        if ($columnInfo['column_key'] === 'PRI') {
            return true;
        }
        return false;
    }

    private function isRequired(array $columnInfo) {
        if ($columnInfo['is_nullable'] === 'NO') {
            return true;
        }
        return false;
    }

    private function isReference(array $columnInfo) {
        if (!empty($columnInfo['referenced_table_name'])) {
            return true;
        }
        return false;
    }

    private function getValidationType(array $columnInfo) {
        $columnType = $columnInfo['column_type'];
        $columnTypeSimple = preg_replace('#\(.+#','',$columnType);
        switch ($columnTypeSimple) {
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