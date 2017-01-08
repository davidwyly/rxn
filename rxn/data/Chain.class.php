<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Utility\Debug;

/**
 * Class Chain
 *
 * @package Rxn\Data
 */
class Chain
{
    /**
     * @var string
     */
    protected $map;

    /**
     * @var array
     */
    public $linksToRecords;

    /**
     * @var array
     */
    public $linksFromRecords;

    /**
     * Chain constructor.
     *
     * @param Map $map
     */
    public function __construct(Map $map)
    {
        $this->map = $map->fingerprint;
        $this->registerDataChain($map);
    }

    /**
     * @param Map $map
     *
     * @throws \Exception
     */
    private function registerDataChain(Map $map)
    {
        $this->validateMap($map);
        $this->registerLinksToRecords($map);
        $this->registerLinksFromRecords($map);
    }

    /**
     * @param Map $map
     */
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

    /**
     * @param Map $map
     */
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

    /**
     * @param $map
     *
     * @throws \Exception
     */
    private function validateMap($map) {
        if (empty($map->tables)) {
            throw new \Exception('',500);
        }
    }
}