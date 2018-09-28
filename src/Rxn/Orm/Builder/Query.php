<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;
use Rxn\Orm\Builder\Query\Select;
use Rxn\Orm\Builder\Query\From;
use Rxn\Orm\Builder\Query\Join;

class Query extends Builder
{
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
     * @param array $columns
     * @param bool  $distinct
     *
     * @return Query
     */
    public function select(array $columns = ['*'], $distinct = false): Query
    {
        $select = new Select();
        $select->selectClause($columns, $distinct);
        $this->loadCommands($select);
        return $this;
    }

    /**
     * @param string      $table
     * @param string|null $alias
     *
     * @return Query
     */
    public function from(string $table, string $alias = null): Query
    {
        $from = new From();
        $from->fromClause($table, $alias);
        $this->loadCommands($from);
        $this->loadTableAliases($from);
        return $this;
    }

    /**
     * @param string      $table
     * @param callable    $callable
     * @param string|null $alias
     * @param string      $type
     *
     * @return Query
     * @throws \Exception
     */
    public function joinCustom(string $table, callable $callable, string $alias = null, string $type = 'inner'): Query
    {
        $join = new Join();
        $join->joinClause($table, $callable, $alias, $type);
        $this->loadCommands($join);
        $this->loadBindings($join);
        $this->loadTableAliases($join);
        return $this;
    }

    /**
     * @param string       $table
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     * @param string       $alias
     *
     * @return Query
     * @throws \Exception
     * @see      innerJoin
     */
    public function join(
        string $table,
        string $first_operand,
        string $operator,
        $second_operand,
        string $alias = null
    ): Query {
        return $this->innerJoin($table, $first_operand, $operator, $second_operand, $alias);
    }

    /**
     * @param string       $table
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     * @param string|null  $alias
     *
     * @return Query
     * @throws \Exception
     */
    public function innerJoin(
        string $table,
        string $first_operand,
        string $operator,
        $second_operand,
        string $alias = null
    ): Query {
        return $this->joinCustom($table,
            function (Join $join) use ($first_operand, $operator, $second_operand, $alias) {
                $join->as($alias);
                $join->on($first_operand, $operator, $second_operand);
            }, $alias, 'inner');
    }

    /**
     * @param string       $table
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     * @param string       $alias
     *
     * @return Query
     * @throws \Exception
     */
    public function leftJoin(
        string $table,
        string $first_operand,
        string $operator,
        $second_operand,
        string $alias
    ): Query {
        return $this->joinCustom($table,
            function (Join $join) use ($first_operand, $operator, $second_operand, $alias) {
                $join->as($alias);
                $join->on($first_operand, $operator, $second_operand);
            }, $alias, 'left');
    }

    /**
     * @param string       $table
     * @param string       $first_operand
     * @param string       $operator
     * @param string|array $second_operand
     * @param string       $alias
     *
     * @return Query
     * @throws \Exception
     */
    public function rightJoin(
        string $table,
        string $first_operand,
        string $operator,
        $second_operand,
        string $alias
    ): Query {
        return $this->joinCustom($table,
            function (Join $join) use ($first_operand, $operator, $second_operand, $alias) {
                $join->as($alias);
                $join->on($first_operand, $operator, $second_operand);
            }, $alias, 'right');
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
        $escaped_operand = $this->escapeReference($first_operand);
        $value           = "$escaped_operand $operator $binding";
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
}
