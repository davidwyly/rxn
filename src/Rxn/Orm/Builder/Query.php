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
 * or pass the Query to Database::run() / Database::fetchAll() via
 * ->toSql().
 */
class Query extends Builder
{
    public const WHERE_OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'IN', 'NOT IN', 'LIKE', 'NOT LIKE',
        'BETWEEN', 'REGEXP', 'NOT REGEXP',
    ];

    public function select(array $columns = ['*'], bool $distinct = false): Query
    {
        $select = new Select();
        $select->set($columns, $distinct);
        $this->loadCommands($select);
        return $this;
    }

    public function from(string $table, ?string $alias = null): Query
    {
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
     * Append a condition.
     *
     * If $callback is provided, it receives a fresh Query whose
     * where* calls are captured as a grouped sub-expression
     * wrapped in parentheses.
     *
     * @param mixed $value
     */
    public function where(string $field, string $operator, $value, ?callable $callback = null, string $type = 'and'): Query
    {
        $this->assertWhereOperator($operator);
        if ($callback !== null) {
            $this->addGroup($type, $field, $operator, $value, $callback);
            return $this;
        }
        $expr = $this->buildCondition($field, $operator, $value);
        $this->commands['WHERE'][] = ['op' => $this->normalizeOp($type), 'expr' => $expr];
        return $this;
    }

    public function andWhere(string $field, string $operator, $value, ?callable $callback = null): Query
    {
        return $this->where($field, $operator, $value, $callback, 'and');
    }

    public function and(string $field, string $operator, $value, ?callable $callback = null): Query
    {
        return $this->andWhere($field, $operator, $value, $callback);
    }

    public function orWhere(string $field, string $operator, $value, ?callable $callback = null): Query
    {
        return $this->where($field, $operator, $value, $callback, 'or');
    }

    public function or(string $field, string $operator, $value, ?callable $callback = null): Query
    {
        return $this->orWhere($field, $operator, $value, $callback);
    }

    public function whereIn(string $field, array $values, string $type = 'and'): Query
    {
        return $this->where($field, 'IN', $values, null, $type);
    }

    public function whereNotIn(string $field, array $values, string $type = 'and'): Query
    {
        return $this->where($field, 'NOT IN', $values, null, $type);
    }

    public function andWhereIn(string $field, array $values): Query { return $this->whereIn($field, $values, 'and'); }
    public function andWhereNotIn(string $field, array $values): Query { return $this->whereNotIn($field, $values, 'and'); }
    public function orWhereIn(string $field, array $values): Query { return $this->whereIn($field, $values, 'or'); }
    public function orWhereNotIn(string $field, array $values): Query { return $this->whereNotIn($field, $values, 'or'); }

    public function whereIsNull(string $field, string $type = 'and'): Query
    {
        $expr = $this->cleanReference($field) . ' IS NULL';
        $this->commands['WHERE'][] = ['op' => $this->normalizeOp($type), 'expr' => $expr];
        return $this;
    }

    public function whereIsNotNull(string $field, string $type = 'and'): Query
    {
        $expr = $this->cleanReference($field) . ' IS NOT NULL';
        $this->commands['WHERE'][] = ['op' => $this->normalizeOp($type), 'expr' => $expr];
        return $this;
    }

    public function andWhereIsNull(string $field): Query { return $this->whereIsNull($field, 'and'); }
    public function andWhereIsNotNull(string $field): Query { return $this->whereIsNotNull($field, 'and'); }
    public function orWhereIsNull(string $field): Query { return $this->whereIsNull($field, 'or'); }
    public function orWhereIsNotNull(string $field): Query { return $this->whereIsNotNull($field, 'or'); }

    public function groupBy(string ...$fields): Query
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

    public function orderBy(string $field, string $direction = 'ASC'): Query
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

    // -- helpers ----------------------------------------------------

    private function assertWhereOperator(string $operator): void
    {
        if (!in_array(strtoupper($operator), array_map('strtoupper', self::WHERE_OPERATORS), true)) {
            throw new \InvalidArgumentException("Unsupported WHERE operator '$operator'");
        }
    }

    private function normalizeOp(string $type): string
    {
        return strtolower($type) === 'or' ? 'OR' : 'AND';
    }

    /**
     * @param mixed $value
     */
    private function buildCondition(string $field, string $operator, $value): string
    {
        $operator = strtoupper($operator);
        $field    = $this->cleanReference($field);
        if ($operator === 'IN' || $operator === 'NOT IN') {
            if (!is_array($value) || $value === []) {
                throw new \InvalidArgumentException("$operator requires a non-empty array");
            }
            $placeholders = [];
            foreach ($value as $v) {
                $placeholders[] = '?';
                $this->bindings[] = $v;
            }
            return $field . ' ' . $operator . ' (' . implode(', ', $placeholders) . ')';
        }
        if ($operator === 'BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new \InvalidArgumentException('BETWEEN requires [low, high]');
            }
            $this->bindings[] = $value[0];
            $this->bindings[] = $value[1];
            return $field . ' BETWEEN ? AND ?';
        }
        $this->bindings[] = $value;
        return $field . ' ' . $operator . ' ?';
    }

    /**
     * @param mixed $value
     */
    private function addGroup(string $type, string $field, string $operator, $value, callable $callback): void
    {
        $sub = new Query();
        $sub->where($field, $operator, $value);
        $callback($sub);
        $this->commands['WHERE'][] = [
            'op'    => $this->normalizeOp($type),
            'group' => $sub->commands['WHERE'] ?? [],
        ];
        foreach ($sub->bindings as $b) {
            $this->bindings[] = $b;
        }
    }
}
