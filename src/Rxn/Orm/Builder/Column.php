<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

final class Column
{
    /**
     * @var string
     */
    public $column;

    /**
     * @var string|null
     */
    public $alias;

    public function __construct($column, $alias = null)
    {
        $this->column = $column;
        $this->alias  = $alias;
    }
}
