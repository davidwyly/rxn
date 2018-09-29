<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder\Query\On;

abstract class Command
{
    /**
     * @var string
     */
    public $command;

    /**
     * @var array
     */
    protected $tables = [];

    protected function isAssociative(array $array)
    {
        if ($array === []) {
            return false;
        }
        ksort($array);
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @param string $reference
     *
     * @return string
     */
    protected function cleanReference(string $reference): string
    {
        $filtered_reference = $this->filterReference($reference);
        //return $this->escapeReference($filtered_reference);
        return $filtered_reference;
    }

    protected function addColumn($column, $alias = null)
    {
        list($column, $table, $database) = $this->listColumnTableDatabase($column);

        $key = null;
        if (!empty($column)) {
            $key = $column;
            if (!empty($alias)) {
                $key = $alias;
            }
        }

        $table = $this->addTable($table, null, $database);

        $table->columns[$key] = new Column($column, $alias);
    }

    protected function addTable($table, $alias = null, $database = null)
    {
        $this->tables[$table] = new Table($table, $alias, $database);
        return $this->tables[$table];
    }

    protected function listColumnTableDatabase($reference)
    {
        preg_match('#\`?(\p{L}+)\`?\.\`?(\p{L}+)\`?#', $reference, $matches);
        if (isset($matches[1]) && isset($matches[2]) && !isset($matches[3])) {
            $table  = $matches[1];
            $column = $matches[2];
            return [$column, $table, null];
        } elseif (isset($matches[3])) {
            $database = $matches[1];
            $table    = $matches[2];
            $column   = $matches[3];
            return [$column, $table, $database];
        }
        $column = $reference;
        return [$column, null, null];
    }

    /**
     * @param string $operand
     *
     * @return string
     */
    protected function escapeReference(string $operand): string
    {
        $exploded_operands = explode('.', $operand);

        $operands = [];
        foreach ($exploded_operands as $exploded_operand) {
            $operands[] = "`$exploded_operand`";
        }
        return implode(".", $operands);
    }

    /**
     * @param string $operand
     *
     * @return string
     */
    protected function filterReference(string $operand): string
    {
        $operand = preg_replace('#[\`\s]#', '', $operand);
        preg_match('#[\p{L}\_\.\-\`0-9]+#', $operand, $matches);
        if (isset($matches[0])) {
            return $matches[0];
        }
        return '';
    }
}
