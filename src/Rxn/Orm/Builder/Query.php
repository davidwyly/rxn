<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;
use Rxn\Orm\Builder\Query\Select;
use Rxn\Orm\Builder\Query\From;
use Rxn\Orm\Builder\Query\Join;
use Rxn\Orm\Builder\Query\Where;

class Query extends Builder
{

    /**
     * @var Command[]
     */
    public $commands;

    /**
     * @param array $columns
     * @param bool  $distinct
     *
     * @return Query
     */
    public function select(array $columns = ['*'], $distinct = false): Query
    {
        $this->commands[] = (new Select())->set($columns, $distinct);
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
        $this->commands[] = (new From())->set($table, $alias);
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
        $this->commands[] = (new Join())->set($table, $callable, $alias, $type);
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
                if (!empty($alias)) {
                    $join->as($alias);
                }
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
                if (!empty($alias)) {
                    $join->as($alias);
                }
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
     * @param string|int    $id
     * @param string        $id_key
     * @param callable|null $callback
     * @param string        $type
     *
     * @return Query
     */
    public function whereId($id, string $id_key = 'id', callable $callback = null, string $type = 'where'): Query
    {
        return $this->where($id_key, '=', $id, $callback, $type);
    }

    public function where(
        string $first_operand,
        string $operator,
        string $second_operand,
        callable $callback = null,
        string $type = 'where'
    ): Query {
        $where = new Where();
        $where->set($first_operand, $operator, $second_operand, $callback, $type);
        $this->commands[] = $where;
        $this->loadBindings($where);
        return $this;
    }

    /**
     * @param string        $operand
     * @param array         $values
     * @param callable|null $callback
     * @param string        $type
     * @param bool          $not
     *
     * @return Query
     */
    public function whereIn(
        string $operand,
        array $values,
        callable $callback = null,
        string $type = 'where',
        $not = false
    ) {
        $where = new Where();
        $where->setIn($operand, $values, $callback, $type, $not);
        $this->loadCommands($where);
        $this->loadBindings($where);
        return $this;
    }

    /**
     * @param string        $operand
     * @param string|array  $values
     * @param callable|null $callback
     * @param string        $type
     *
     * @return Query
     * @throws \Exception
     */
    public function whereNotIn(string $operand, array $values, callable $callback = null, string $type = 'where')
    {
        return $this->whereIn($operand, $values, $callback, $type, true);
    }

    /**
     * @param string        $operand
     * @param callable|null $callback
     * @param string        $type
     * @param bool          $not
     *
     * @return $this
     */
    public function whereIsNull(string $operand, callable $callback = null, $type = 'where', $not = false)
    {
        $where = new Where();
        $where->setNull($operand, $callback, $type, $not);
        $this->loadCommands($where);
        $this->loadBindings($where);
        return $this;
    }

    /**
     * @param string        $operand
     * @param callable|null $callback
     * @param string        $type
     *
     * @return Query
     */
    public function whereIsNotNull(string $operand, callable $callback = null, $type = 'where')
    {
        return $this->whereIsNull($operand, $callback, $type, true);
    }

    /**
     * @param string        $first_operand
     * @param string        $operator
     * @param string        $second_operand
     * @param callable|null $callback
     *
     * @return Query
     */
    public function and (
        string $first_operand,
        string $operator,
        string $second_operand,
        callable $callback = null
    ): Query {
        return $this->andWhere($first_operand, $operator, $second_operand, $callback);
    }

    /**
     * @param string        $first_operand
     * @param string        $operator
     * @param string        $second_operand
     * @param callable|null $callback
     *
     * @return Query
     */
    public function andWhere(
        string $first_operand,
        string $operator,
        string $second_operand,
        callable $callback = null
    ): Query {
        return $this->where($first_operand, $operator, $second_operand, $callback, 'and');
    }

    /**
     * @param string        $operand
     * @param               $values
     * @param callable|null $callback
     *
     * @return Query
     */
    public function andWhereIn(string $operand, $values, callable $callback = null)
    {
        return $this->whereIn($operand, $values, $callback, 'and');
    }

    /**
     * @param string        $operand
     * @param               $values
     * @param callable|null $callback
     *
     * @return Query
     * @throws \Exception
     */
    public function andWhereNotIn(string $operand, $values, callable $callback = null)
    {
        return $this->whereNotIn($operand, $values, $callback, 'and');
    }

    /**
     * @param string        $operand
     * @param callable|null $callback
     *
     * @return Query
     */
    public function andWhereIsNull(string $operand, callable $callback = null)
    {
        return $this->whereIsNull($operand, $callback, 'and');
    }

    /**
     * @param string        $operand
     * @param callable|null $callback
     *
     * @return Query
     */
    public function andWhereIsNotNull(string $operand, callable $callback = null)
    {
        return $this->whereIsNotNull($operand, $callback, 'and');
    }

    /**
     * @param string        $first_operand
     * @param string        $operator
     * @param               $second_operand
     * @param callable|null $callback
     *
     * @return Query
     */
    public function or (string $first_operand, string $operator, $second_operand, callable $callback = null): Query
    {
        return $this->orWhere($first_operand, $operator, $second_operand, $callback);
    }

    /**
     * @param string        $first_operand
     * @param string        $operator
     * @param               $second_operand
     * @param callable|null $callback
     *
     * @return Query
     */
    public function orWhere(string $first_operand, string $operator, $second_operand, callable $callback = null): Query
    {
        return $this->where($first_operand, $operator, $second_operand, $callback, 'or');
    }

    /**
     * @param string        $operand
     * @param               $values
     * @param callable|null $callback
     *
     * @return Query
     */
    public function orWhereIn(string $operand, $values, callable $callback = null)
    {
        return $this->whereIn($operand, $values, $callback, 'or');
    }

    /**
     * @param string        $operand
     * @param               $values
     * @param callable|null $callback
     *
     * @return Query
     * @throws \Exception
     */
    public function orWhereNotIn(string $operand, $values, callable $callback = null)
    {
        return $this->whereNotIn($operand, $values, $callback, 'or');
    }

    /**
     * @param string        $operand
     * @param callable|null $callback
     *
     * @return Query
     */
    public function orWhereIsNull(string $operand, callable $callback = null)
    {
        return $this->whereIsNull($operand, $callback, 'or');
    }

    /**
     * @param string        $operand
     * @param callable|null $callback
     *
     * @return Query
     */
    public function orWhereIsNotNull(string $operand, callable $callback = null)
    {
        return $this->whereIsNotNull($operand, $callback, 'or');
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
