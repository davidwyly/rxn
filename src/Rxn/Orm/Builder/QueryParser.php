<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;

class QueryParser
{
    /**
     * @var Builder
     */
    private $builder;

    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
    }

    public function getSql()
    {
        $sql = '';
        $sql .= $this->select();
        $sql .= $this->from();
        $sql .= $this->innerJoin();
        $sql .= $this->leftJoin();
        $sql .= $this->rightJoin();
        $sql .= $this->where();
        $sql .= $this->or();
        return $sql;
    }

    private function select()
    {
        if (!array_key_exists('SELECT', $this->builder->commands)) {
            return '';
        }

        $select = $this->builder->commands['SELECT'];
        if ($select == ['*']) {
            return "SELECT * \r\n";
        }
        return "SELECT \r\n    " . implode(",\r\n    ", $select) . " \r\n";
    }

    private function from()
    {
        if (!array_key_exists('FROM', $this->builder->commands)) {
            return '';
        }

        $from = $this->builder->commands['FROM'];
        return "FROM " . implode(",\r\n    ", $from) . " \r\n";
    }

    private function innerJoin()
    {
        if (!array_key_exists('INNER JOIN', $this->builder->commands)) {
            return '';
        }
        return $this->join('INNER JOIN');
    }

    private function leftJoin()
    {
        if (!array_key_exists('LEFT JOIN', $this->builder->commands)) {
            return '';
        }
        return $this->join('LEFT JOIN');
    }

    private function rightJoin()
    {
        if (!array_key_exists('RIGHT JOIN', $this->builder->commands)) {
            return '';
        }
        return $this->join('RIGHT JOIN');
    }

    private function where() {
        if (!array_key_exists('WHERE', $this->builder->commands)) {
            return '';
        }

        $where = $this->builder->commands['WHERE'];

        $sql = '';
        $used_initial_where = false;
        foreach ($where as $key => $value) {
            if (is_numeric($key) && is_array($value)) {
                // in a group
                foreach ($value as $grouped_command => $commands) {
                    switch ($grouped_command) {
                        case 'WHERE':
                            $grouped_command = ($used_initial_where) ? 'AND' : 'WHERE';
                            $used_initial_where = true;
                            $commands_imploded = implode(" \r\n    AND ",$commands);
                            $sql .= "$grouped_command (\r\n    $commands_imploded \r\n)\r\n";
                    }
                }
            }
        }
        return $sql;
    }

    private function or() {
        if (!array_key_exists('WHERE', $this->builder->commands)) {
            return '';
        }

        $where = $this->builder->commands['WHERE'];

        $sql = '';
        $used_initial_where = false;
        foreach ($where as $key => $value) {
            if (is_numeric($key) && is_array($value)) {
                // in a group
                foreach ($value as $grouped_command => $commands) {
                    switch ($grouped_command) {
                        case 'WHERE':
                            $grouped_command = ($used_initial_where) ? 'AND' : 'WHERE';
                            $used_initial_where = true;
                            $commands_imploded = implode(" \r\n    AND ",$commands);
                            $sql .= "$grouped_command (\r\n    $commands_imploded \r\n)\r\n";
                    }
                }
            }
        }
        return $sql;
    }

    private function join($command) {
        $inner_join = $this->builder->commands[$command];

        $sql = '';
        foreach ($inner_join as $table => $conditions) {
            $sql .= "$command `$table` ";
            foreach ($conditions as $condition => $expressions) {
                $sql .= "$condition ";
                foreach ($expressions as $expression) {
                    $sql .= "$expression ";
                }
            }
            $sql .= "\r\n";
        }
        return $sql;
    }


}
