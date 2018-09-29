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
        $this->commands[] = (new Select())->select($columns, $distinct);
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
        $this->commands[] = (new From())->from($table, $alias);
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
        $this->commands[] = (new Join())->join($table, $callable, $alias, $type);
        return $this;
    }

    /**
     * @param string        $table
     * @param string        $operand_1
     * @param string        $operator
     * @param string|array  $operand_2
     * @param string        $alias
     * @param callable|null $callback
     *
     * @return Query
     * @throws \Exception
     * @see      innerJoin
     */
    public function join(
        string $table,
        string $operand_1,
        string $operator,
        $operand_2,
        string $alias = null,
        callable $callback = null
    ): Query {
        return $this->innerJoin($table, $operand_1, $operator, $operand_2, $alias, $callback);
    }

    /**
     * @param string        $table
     * @param string        $operand_1
     * @param string        $operator
     * @param string|array  $operand_2
     * @param string|null   $alias
     * @param callable|null $callback
     *
     * @return Query
     * @throws \Exception
     */
    public function innerJoin(
        string $table,
        string $operand_1,
        string $operator,
        $operand_2,
        string $alias = null,
        callable $callback = null
    ): Query {
        return $this->joinCustom($table,
            function (Join $join) use ($operand_1, $operator, $operand_2, $alias, $callback) {
                $join->on($operand_1, $operator, $operand_2);
                if (!is_null($callback)) {
                    call_user_func($callback, $join);
                }
            }, $alias, 'inner');
    }

    /**
     * @param string        $table
     * @param string        $operand_1
     * @param string        $operator
     * @param string|array  $operand_2
     * @param string        $alias
     * @param callable|null $callback
     *
     * @return Query
     * @throws \Exception
     */
    public function leftJoin(
        string $table,
        string $operand_1,
        string $operator,
        $operand_2,
        string $alias,
        callable $callback = null
    ): Query {
        return $this->joinCustom($table,
            function (Join $join) use ($operand_1, $operator, $operand_2, $alias, $callback) {
                $join->on($operand_1, $operator, $operand_2);
                if (!is_null($callback)) {
                    call_user_func($callback, $join);
                }
            }, $alias, 'left');
    }

    /**
     * @param string       $table
     * @param string       $operand_1
     * @param string       $operator
     * @param string|array $operand_2
     * @param string       $alias
     *
     * @return Query
     * @throws \Exception
     */
    public function rightJoin(
        string $table,
        string $operand_1,
        string $operator,
        $operand_2,
        string $alias
    ): Query {
        return $this->joinCustom($table, function (Join $join) use ($operand_1, $operator, $operand_2, $alias) {
            $join->on($operand_1, $operator, $operand_2);
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
        string $operand_1,
        string $operator,
        string $operand_2,
        callable $callback = null,
        string $type = 'where'
    ): Query {
        $this->commands[] = (new Where())->where($operand_1, $operator, $operand_2, $callback, $type);
        return $this;
    }

    public function whereIn(
        string $operand,
        array $operands,
        callable $callback = null,
        string $type = 'where',
        $not = false
    ) {
        $this->commands[] = (new Where())->in($operand, $operands, $callback, $type, $not);
        return $this;
    }

    public function whereNotIn(string $operand, array $operands, callable $callback = null, string $type = 'where')
    {
        return $this->whereIn($operand, $operands, $callback, $type, true);
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
        $this->commands[] = (new Where())->isNull($operand, $callback, $type, $not);
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
     * @param string        $operand_1
     * @param string        $operator
     * @param string        $operand_2
     * @param callable|null $callback
     *
     * @return Query
     */
    public function and (string $operand_1, string $operator, string $operand_2, callable $callback = null): Query
    {
        return $this->andWhere($operand_1, $operator, $operand_2, $callback);
    }

    /**
     * @param string        $operand_1
     * @param string        $operator
     * @param string        $operand_2
     * @param callable|null $callback
     *
     * @return Query
     */
    public function andWhere(string $operand_1, string $operator, string $operand_2, callable $callback = null): Query
    {
        return $this->where($operand_1, $operator, $operand_2, $callback, 'and');
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
     * @param string        $operand_1
     * @param string        $operator
     * @param               $operand_2
     * @param callable|null $callback
     *
     * @return Query
     */
    public function or (string $operand_1, string $operator, $operand_2, callable $callback = null): Query
    {
        return $this->orWhere($operand_1, $operator, $operand_2, $callback);
    }

    /**
     * @param string        $operand_1
     * @param string        $operator
     * @param               $operand_2
     * @param callable|null $callback
     *
     * @return Query
     */
    public function orWhere(string $operand_1, string $operator, $operand_2, callable $callback = null): Query
    {
        return $this->where($operand_1, $operator, $operand_2, $callback, 'or');
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
