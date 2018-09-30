<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;
use Rxn\Orm\Builder\Command\Select;
use Rxn\Orm\Builder\Command\From;
use Rxn\Orm\Builder\Command\Join;
use Rxn\Orm\Builder\Command\Where;
use Rxn\Orm\Builder\Command\Group;
use Rxn\Orm\Builder\Command\Limit;
use Rxn\Orm\Builder\Command\Offset;
use Rxn\Orm\Builder\Command\Order;
use Rxn\Orm\Builder\Command\Having;

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

    public function from(string $table): Query
    {
        $this->commands[] = (new From())->from($table);
        return $this;
    }

    /**
     * @param string      $table
     * @param string|null $alias
     *
     * @return Query
     */
    public function fromAs(string $table, string $alias = null): Query
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

    public function join(string $table, string $operand_1, $operand_2): Query
    {
        return $this->innerJoin($table, function (Where $where) use ($operand_1, $operand_2) {
            $where->equals($operand_1, $operand_2);
        });
    }

    public function innerJoin(string $table, callable $callback): Query
    {
        $this->commands[] = (new Join())->join($table, $callback, null, 'inner');
        return $this;
    }

    public function innerJoinAs(string $table, string $alias, callable $callback): Query
    {
        $this->commands[] = (new Join())->join($table, $callback, $alias, 'inner');
        return $this;
    }

    public function leftJoin(
        string $table,
        callable $callback = null
    ): Query {
        $this->commands[] = (new Join())->join($table, $callback, null, 'left');
        return $this;
    }

    public function leftJoinAs(string $table, string $alias, callable $callable): Query
    {
        $this->commands[] = (new Join())->join($table, $callable, $alias, 'left');
        return $this;
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

    public function where(callable $callback): Query {
        $this->commands[] = (new Where())->and($callback)->unset();
        return $this;
    }

    public function groupBy(array $columns): Query
    {
        $this->commands[] = (new Group())->groupBy($columns);
        return $this;
    }

    public function orderBy(array $columns): Query
    {
        $this->commands[] = (new Order())->orderBy($columns);
        return $this;
    }

    public function limit(int $limit): Query
    {
        $this->commands[] = (new Limit())->limit($limit);
        return $this;
    }

    public function offset(int $offset): Query
    {
        $this->commands[] = (new Offset())->offset($offset);
        return $this;
    }

    public function having(callable $callback): Query
    {
        $this->commands[] = (new Having())->having($callback);
        return $this;
    }

    public function union(): Query
    {

    }
}
