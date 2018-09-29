<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

final class Table
{
    /**
     * @var string|null
     */
    public $database;

    /**
     * @var string
     */
    public $table;

    /**
     * @var string|null
     */
    public $alias;

    /**
     * @var Column[]
     */
    public $columns;

    public function __construct($table, $alias = null, $database = null)
    {
        $this->table    = $table;
        $this->alias    = $alias;
        $this->database = $database;
    }
}
