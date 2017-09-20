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

namespace Rxn\Data;

class Chain
{
    /**
     * @var Map
     */
    protected $map;

    /**
     * @var array
     */
    public $links_to_records;

    /**
     * @var array
     */
    public $links_from_records;

    /**
     * Chain constructor.
     *
     * @param Map $map
     *
     * @throws \Exception
     */
    public function __construct(Map $map)
    {
        $this->map = $map;
        $this->registerDataChain();
    }

    /**
     * @throws \Exception
     */
    private function registerDataChain()
    {
        $this->validateMap();
        $this->registerLinks();
    }

    /**
     *
     */
    private function registerLinks()
    {
        $tables = $this->map->getTables();
        foreach ($tables as $table_name => $table_map) {
            if (isset($table_map->field_references)) {
                foreach ($table_map->field_references as $column => $reference_table_info) {
                    $referenceTable = $reference_table_info['table'];
                    if (array_key_exists($referenceTable, $tables)) {
                        $matchingTable     = $tables[$referenceTable];
                        $matchingTableName = $matchingTable->name;

                        // define 'belongs to' relations
                        $this->links_to_records[$table_name][$column] = $matchingTableName;

                        // define 'has many' relations
                        $this->links_from_records[$matchingTableName][$table_name] = $column;
                    }
                }
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function validateMap()
    {
        if (empty($this->map->getTables())) {
            throw new \Exception('', 500);
        }
    }
}
