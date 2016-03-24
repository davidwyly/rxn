<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Utility\Debug;

class Chain
{
    protected $map;
    public $linksToRecords;
    public $linksFromRecords;

    public function __construct(Map $map)
    {
        $this->map = $map->fingerprint;
        $this->registerDataChain($map);
    }

    private function registerDataChain(Map $map)
    {
        $this->validateMap($map);
        $this->registerLinksToRecords($map);
        $this->registerLinksFromRecords($map);
    }

    private function registerLinksToRecords(Map $map) {
        foreach ($map->tables as $tableName=>$tableMap) {
            if (isset($tableMap->fieldReferences)) {
                foreach ($tableMap->fieldReferences as $column=>$referenceTableInfo) {
                    $referenceTable = $referenceTableInfo['table'];
                    if (array_key_exists($referenceTable, $map->tables)) {
                        $matchingTable = $map->tables[$referenceTable];
                        $matchingTableName = $matchingTable->name;
                        $this->linksToRecords[$tableName][$column] = $matchingTableName;
                    }
                }
            }
        }
    }

    private function registerLinksFromRecords(Map $map)
    {

        foreach ($map->tables as $tableName=>$tableMap) {
            if (isset($tableMap->fieldReferences)) {
                foreach ($tableMap->fieldReferences as $column=>$referenceTableInfo) {
                    $referenceTable = $referenceTableInfo['table'];
                    if (array_key_exists($referenceTable, $map->tables)) {
                        $matchingTable = $map->tables[$referenceTable];
                        $matchingTableName = $matchingTable->name;
                        $this->linksFromRecords[$matchingTableName][$tableName] = $column;
                    }
                }
            }
        }
    }

    private function validateMap($map) {
        if (empty($map->tables)) {
            throw new \Exception();
        }
    }
}