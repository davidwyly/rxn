<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

class Query
{

    const JOIN_COMMANDS = [
        'inner' => 'INNER JOIN',
        'left'  => 'LEFT JOIN',
        'right' => 'RIGHT JOIN',
    ];

    const WHERE_COMMANDS = [
        'where' => 'WHERE',
        'and'   => 'AND',
        'or'    => 'OR',
    ];

    const WHERE_OPERATORS = [
        '=',
        '!=',
        '<>',
        'IN',
        'NOT IN',
        'LIKE',
        'NOT LIKE',
        'BETWEEN',
        'REGEXP',
        'NOT REGEXP',
        '<',
        '<=',
        '>',
        '>=',
    ];

    /**
     * @var array
     */
    public $commands = [];

    /**
     * @var array
     */
    public $bindings = [];

    /**
     * @param array $columns
     * @param bool  $distinct
     *
     * @return Query
     */
    public function select(array $columns = ['*'], $distinct = false): Query
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
    private function selectAll($distinct = false)
    {
        $command = ($distinct) ? 'SELECT DISTINCT' : 'SELECT';
        $this->addCommand($command, "*");
    }

    /**
     * @param array $columns
     * @param bool  $distinct
     */
    private function selectAssociative(array $columns, $distinct = false)
    {
        $command = ($distinct) ? 'SELECT DISTINCT' : 'SELECT';
        foreach ($columns as $column => $alias) {
            $filtered_column = $this->filterString($column);
            $escaped_reference = $this->escapedReference($filtered_column);
            $value = "$escaped_reference AS `{$this->filterString($alias)}`";
            $this->addCommand($command, $value);
        }
    }

    /**
     * @param array $columns
     * @param bool  $distinct
     */
    private function selectNumerical(array $columns, $distinct = false)
    {
        $command = ($distinct) ? 'SELECT DISTINCT' : 'SELECT';
        foreach ($columns as $column) {
            $value = "`{$this->filterString($column)}`";
            $this->addCommand($command, $value);
        }
    }

    /**
     * @param string $table
     *
     * @return Query
     */
    public function from($table): Query
    {
        $value = "`{$this->filterString($table)}`";
        $this->addCommand('FROM', $value);
        return $this;
    }

    /**
     * @param string   $table
     * @param callable $join_callable
     * @param string   $type
     *
     * @return Query
     * @throws \Exception
     */
    public function joinCustom(string $table, callable $join_callable, string $type = 'inner'): Query
    {
        if (!array_key_exists($type, self::JOIN_COMMANDS)) {
            throw new \Exception("");
        }
        $command = self::JOIN_COMMANDS[$type];
        $join    = new Join($table);
        call_user_func($join_callable, $join);
        $this->addCommandWithKey($command, $join->commands, $table);
        $this->addBindings($join->bindings);
        return $this;
    }

    /**
     * @param string       $table
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     *
     * @return Query
     * @throws \Exception
     * @see      innerJoin
     */
    public function join(string $table, string $first_operand, string $operator, $second_operand): Query
    {
        return $this->innerJoin($table, $first_operand, $operator, $second_operand);
    }

    /**
     * @param string       $table
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     *
     * @return Query
     * @throws \Exception
     */
    public function innerJoin(string $table, string $first_operand, string $operator, $second_operand): Query
    {
        return $this->joinCustom($table, function (Join $join) use ($first_operand, $operator, $second_operand) {
            $join->on($first_operand, $operator, $second_operand);
        }, 'inner');
    }

    /**
     * @param string       $table
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     *
     * @return Query
     * @throws \Exception
     */
    public function leftJoin(string $table, string $first_operand, string $operator, $second_operand): Query
    {
        return $this->joinCustom($table, function (Join $join) use ($first_operand, $operator, $second_operand) {
            $join->on($first_operand, $operator, $second_operand);
        }, 'left');
    }

    /**
     * @param string       $table
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     *
     * @return Query
     * @throws \Exception
     */
    public function rightJoin(string $table, string $first_operand, string $operator, $second_operand): Query
    {
        return $this->joinCustom($table, function (Join $join) use ($first_operand, $operator, $second_operand) {
            $join->on($first_operand, $operator, $second_operand);
        }, 'right');
    }

    /**
     * @return Query
     */
    public function crossJoin(): Query
    {
        // TODO
    }

    /**
     * @return Query
     */
    public function naturalJoin(): Query
    {
        // TODO
    }

    /**
     * @param string|int $id
     *
     * @return Query
     */
    public function whereId($id): Query
    {
        $binding = 'id';
        $value   = "`$binding` = :$binding";
        $this->addCommand('WHERE', $value);
        $this->addBinding($id);
        return $this;
    }

    /**
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     * @param string       $type
     *
     * @return Query
     * @throws \Exception
     */
    public function where(string $first_operand, string $operator, $second_operand, $type = 'where'): Query
    {

        if (!array_key_exists($type, self::WHERE_COMMANDS)) {
            throw new \Exception("");
        }

        list($binding, $bindings) = $this->getOperandBindings($second_operand);
        $escaped_operand = $this->escapedReference($first_operand);
        $value = "$escaped_operand $operator $binding";
        $this->addBindings($bindings);

        $command = self::WHERE_COMMANDS[$type];
        $this->addCommand($command, $value);
        return $this;
    }

    /**
     * @param string $operand
     * @param        $values
     * @param string $type
     *
     * @return Query
     * @throws \Exception
     */
    public function whereIn(string $operand, $values, $type = 'where')
    {
        return $this->where($operand, 'IN', $values, $type);
    }

    /**
     * @param string $operand
     * @param        $values
     * @param string $type
     *
     * @return Query
     * @throws \Exception
     */
    public function whereNotIn(string $operand, $values, $type = 'where')
    {
        return $this->where($operand, 'NOT IN', $values, $type);
    }

    /**
     * @param string $operand
     * @param string $type
     * @param bool   $not
     *
     * @return $this
     * @throws \Exception
     */
    public function whereIsNull(string $operand, $type = 'where', $not = false)
    {
        if (!array_key_exists($type, self::WHERE_COMMANDS)) {
            throw new \Exception("");
        }
        $operator = ($not) ? 'IS NOT NULL' : 'IS NULL';
        $value    = "`$operand` $operator";
        $command  = self::WHERE_COMMANDS[$type];
        $this->addCommand($command, $value);
        return $this;
    }

    protected function escapedReference($operand)
    {
        $exploded_operand = explode('.', $operand);
        if (count($exploded_operand) === 2) {
            return "`{$exploded_operand[0]}`.`{$exploded_operand[1]}`";
        }
        return "`$operand`";
    }

    /**
     * @param string $operand
     * @param string $type
     *
     * @return Query
     * @throws \Exception
     */
    public function whereIsNotNull(string $operand, $type = 'where')
    {
        return $this->whereIsNull($operand, $type, true);
    }

    /**
     * @param string $first_operand
     * @param string $operator
     * @param        $second_operand
     *
     * @return Query
     * @throws \Exception
     */
    public function andWhere(string $first_operand, string $operator, $second_operand): Query
    {
        return $this->where($first_operand, $operator, $second_operand, 'and');
    }

    /**
     * @param string $operand
     * @param        $values
     *
     * @return Query
     * @throws \Exception
     */
    public function andWhereIn(string $operand, $values)
    {
        return $this->whereIn($operand, $values, 'and');
    }

    /**
     * @param string $operand
     * @param        $values
     *
     * @return Query
     * @throws \Exception
     */
    public function andWhereNotIn(string $operand, $values)
    {
        return $this->whereNotIn($operand, $values, 'and');
    }

    /**
     * @param string $operand
     *
     * @return Query
     * @throws \Exception
     */
    public function andWhereIsNull(string $operand)
    {
        return $this->whereIsNull($operand, 'and');
    }

    /**
     * @param string $operand
     *
     * @return Query
     * @throws \Exception
     */
    public function andWhereIsNotNull(string $operand)
    {
        return $this->whereIsNotNull($operand, 'and');
    }

    /**
     * @param string $first_operand
     * @param string $operator
     * @param        $second_operand
     *
     * @return Query
     * @throws \Exception
     */
    public function orWhere(string $first_operand, string $operator, $second_operand): Query
    {
        return $this->where($first_operand, $operator, $second_operand, 'or');
    }

    /**
     * @param string $operand
     * @param        $values
     *
     * @return Query
     * @throws \Exception
     */
    public function orWhereIn(string $operand, $values)
    {
        return $this->whereIn($operand, $values, 'or');
    }

    /**
     * @param string $operand
     * @param        $values
     *
     * @return Query
     * @throws \Exception
     */
    public function orWhereNotIn(string $operand, $values)
    {
        return $this->whereNotIn($operand, $values, 'or');
    }

    /**
     * @param string $operand
     *
     * @return Query
     * @throws \Exception
     */
    public function orWhereIsNull(string $operand)
    {
        return $this->whereIsNull($operand, 'or');
    }

    /**
     * @param string $operand
     *
     * @return Query
     * @throws \Exception
     */
    public function orWhereIsNotNull(string $operand)
    {
        return $this->whereIsNotNull($operand, 'or');
    }

    public function groupBy(): Query
    {
        return $this;
    }

    public function orderBy(): Query
    {

    }

    public function limit(): Query
    {

    }

    public function offset(): Query
    {

    }

    public function having(): Query
    {

    }

    public function union(): Query
    {

    }

    /**
     * @param string $string
     *
     * @return string
     */
    protected function filterString(string $string): string
    {
        preg_match('#[\p{L}\_\.]+#', $string, $matches);
        if (isset($matches[0])) {
            return $matches[0];
        }
        return '';
    }

    protected function isAssociative(array $array)
    {
        if ([] === $array) {
            return false;
        }
        ksort($array);
        return array_keys($array) !== range(0, count($array) - 1);
    }

    protected function addCommandWithKey($command, $value, $key)
    {
        $this->commands[$command][$key] = $value;
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }

    protected function addBindings($key_values)
    {
        if (empty($key_values)) {
            return false;
        }
        foreach ($key_values as $value) {
            $this->addBinding($value);
        }
    }

    protected function addBinding($value)
    {
        $this->bindings[] = $value;
    }

    protected function getOperandBindings($operand): array
    {
        if (is_array($operand)) {
            $bindings     = [];
            $parsed_array = [];
            if (empty($bindings)) {
                foreach ($operand as $value) {
                    $parsed_array[] = '?';
                    $bindings[]     = $value;
                }
                return ['(' . implode(",", $parsed_array) . ')', $bindings];
            }
        }

        return ['?', [$operand]];
    }
}
