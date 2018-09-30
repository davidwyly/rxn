<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;

class Select extends Command
{
    /**
     * @var string
     */
    public $command;

    /**
     * @param array $columns
     * @param bool  $distinct
     *
     * @return $this
     */
    public function select(array $columns = ['*'], $distinct = false)
    {
        if ($columns === ['*']
            || empty($columns)
        ) {
            $this->selectAll($distinct);
        } elseif ($this->isAssociative($columns)) {
            $this->selectAssociative($columns, $distinct);
        } else {
            $this->selectNumerical($columns, $distinct);
        }
        return $this;
    }

    /**
     * @param bool $distinct
     */
    public function selectAll($distinct = false)
    {
        $this->command = ($distinct) ? 'SELECT DISTINCT' : 'SELECT';
        $this->addColumn('*');
    }

    /**
     * @param array $columns
     * @param bool  $distinct
     */
    public function selectAssociative(array $columns, $distinct = false)
    {
        $this->command = ($distinct) ? 'SELECT DISTINCT' : 'SELECT';
        foreach ($columns as $column => $alias) {
            if (is_numeric($column)) {
                $column = $alias;
            }
            $column = $this->cleanReference($column);
            $this->addColumn($column, $alias);
        }
    }

    /**
     * Converts a numerical array into an associative array
     *
     * @param array $numerical_columns
     * @param bool  $distinct
     */
    public function selectNumerical(array $numerical_columns, $distinct = false)
    {
        $associative_columns = [];
        foreach ($numerical_columns as $key => $numerical_column) {
            $numerical_column = trim($numerical_column);
            $clauses          = preg_split('#(\s*\,\s*)+#', $numerical_column);
            if (count($clauses) > 1) {
                $clauses = array_reverse($clauses);
                unset($numerical_columns[$key]);
                foreach ($clauses as $clause) {
                    array_unshift($numerical_columns, $clause);
                }
            }
        }
        foreach ($numerical_columns as $key => $numerical_column) {
            $splits_in_clause = preg_split('#\s+[aA][sS]\s+#', $numerical_column);
            if (count($splits_in_clause) == 2) {
                unset($numerical_columns[$key]);
                $reference                       = array_shift($splits_in_clause);
                $alias                           = array_shift($splits_in_clause);
                $associative_columns[$reference] = $alias;
            }
        }
        if (!empty($numerical_columns)) {
            foreach ($numerical_columns as $column) {
                $associative_columns[$column] = null;
            }
        }
        $this->selectAssociative($associative_columns, $distinct);
    }
}
