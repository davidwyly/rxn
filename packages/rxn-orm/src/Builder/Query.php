<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;
use Rxn\Orm\Builder\Query\Select;
use Rxn\Orm\Builder\Query\From;
use Rxn\Orm\Builder\Query\Join;

/**
 * Fluent SELECT query builder. Accumulates commands into
 * $this->commands and bindings into $this->bindings; call toSql()
 * to materialize the final (string $sql, array $bindings) tuple,
 * or pass the Query to Database::run() to execute in one call.
 */
class Query extends Builder implements Buildable
{
    use HasWhere;

    public function select(array $columns = ['*'], bool $distinct = false): Query
    {
        $select = new Select();
        $select->set($columns, $distinct);
        $this->loadCommands($select);
        return $this;
    }

    /**
     * Append a subquery as a SELECT column.
     *
     *   $sub = (new Query())->select([Raw::of('COUNT(*)')])
     *       ->from('orders')
     *       ->where('user_id', '=', Raw::of('u.id'));
     *   $q->select(['u.id'])->selectSubquery($sub, 'order_count')->from('users', 'u');
     *
     * Bindings from the subquery are merged into the outer Query's
     * binding list at call time, so invoke selectSubquery before
     * methods whose placeholders appear later in the resulting SQL
     * (typically: before where() / groupBy() / etc).
     */
    public function selectSubquery(Buildable $subquery, string $alias): Query
    {
        if ($alias === '') {
            throw new \InvalidArgumentException('selectSubquery requires a non-empty alias');
        }
        [$sub_sql, $sub_bindings] = $subquery->toSql();
        $command = isset($this->commands['SELECT DISTINCT']) ? 'SELECT DISTINCT' : 'SELECT';
        $this->commands[$command][] = '(' . $sub_sql . ') AS `' . trim($alias, '`') . '`';
        foreach ($sub_bindings as $b) {
            $this->bindings[] = $b;
        }
        return $this;
    }

    /**
     * @param string|Buildable $table bare identifier, or a nested
     *        Buildable whose SQL becomes `(subquery) AS alias`.
     */
    public function from($table, ?string $alias = null): Query
    {
        if ($table instanceof Buildable) {
            if ($alias === null || $alias === '') {
                throw new \InvalidArgumentException('FROM subquery requires an alias');
            }
            [$sub_sql, $sub_bindings] = $table->toSql();
            $this->commands['FROM'][] = '(' . $sub_sql . ') AS `' . trim($alias, '`') . '`';
            foreach ($sub_bindings as $b) {
                $this->bindings[] = $b;
            }
            return $this;
        }
        $from = new From();
        $from->set($table, $alias);
        $this->loadCommands($from);
        $this->loadTableAliases($from);
        return $this;
    }

    public function joinCustom(string $table, callable $callable, ?string $alias = null, string $type = 'inner'): Query
    {
        $join = new Join();
        $join->set($table, $callable, $alias, $type);
        $this->loadCommands($join);
        $this->loadBindings($join);
        $this->loadTableAliases($join);
        return $this;
    }

    public function join(string $table, string $first_operand, string $operator, $second_operand, ?string $alias = null): Query
    {
        return $this->innerJoin($table, $first_operand, $operator, $second_operand, $alias);
    }

    public function innerJoin(string $table, string $first_operand, string $operator, $second_operand, ?string $alias = null): Query
    {
        return $this->simpleJoin('inner', $table, $first_operand, $operator, $second_operand, $alias);
    }

    public function leftJoin(string $table, string $first_operand, string $operator, $second_operand, ?string $alias = null): Query
    {
        return $this->simpleJoin('left', $table, $first_operand, $operator, $second_operand, $alias);
    }

    public function rightJoin(string $table, string $first_operand, string $operator, $second_operand, ?string $alias = null): Query
    {
        return $this->simpleJoin('right', $table, $first_operand, $operator, $second_operand, $alias);
    }

    private function simpleJoin(string $type, string $table, string $first_operand, string $operator, $second_operand, ?string $alias): Query
    {
        return $this->joinCustom($table, function (Join $join) use ($first_operand, $operator, $second_operand, $alias) {
            if (!empty($alias)) {
                $join->as($alias);
            }
            $join->on($first_operand, $operator, $second_operand);
        }, $alias, $type);
    }

    public function whereId($id, string $id_key = 'id'): Query
    {
        return $this->where($id_key, '=', $id);
    }

    /**
     * @param string|Raw ...$fields
     */
    public function groupBy(...$fields): Query
    {
        foreach ($fields as $field) {
            $this->commands['GROUP BY'][] = $this->cleanReference($field);
        }
        return $this;
    }

    public function having(string $expression): Query
    {
        $this->commands['HAVING'][] = $expression;
        return $this;
    }

    /**
     * @param string|Raw $field
     */
    public function orderBy($field, string $direction = 'ASC'): Query
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException("orderBy direction must be ASC or DESC, got '$direction'");
        }
        $this->commands['ORDER BY'][] = $this->cleanReference($field) . ' ' . $direction;
        return $this;
    }

    public function limit(int $count): Query
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('limit must be non-negative');
        }
        $this->commands['LIMIT'] = [$count];
        return $this;
    }

    public function offset(int $count): Query
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('offset must be non-negative');
        }
        $this->commands['OFFSET'] = [$count];
        return $this;
    }

    /**
     * Materialize the builder state into a single SQL string +
     * positional-bindings array.
     *
     * @return array{0: string, 1: array}
     */
    public function toSql(): array
    {
        $parser = new QueryParser($this);
        return [$parser->getSql(), array_values($this->bindings)];
    }
}
