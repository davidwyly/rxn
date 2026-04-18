<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

/**
 * Shared WHERE-clause API for Query, Update, and Delete.
 *
 * Stores conditions on $this->commands['WHERE'] as an ordered list
 * of ['op' => AND|OR, 'expr' => string] / ['op', 'group' => entries]
 * entries, and accumulates placeholders into $this->bindings.
 * Consumers either pass themselves to `QueryParser` (which emits the
 * WHERE clause) or implement equivalent rendering.
 */
trait HasWhere
{
    public const WHERE_OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'IN', 'NOT IN', 'LIKE', 'NOT LIKE',
        'BETWEEN', 'REGEXP', 'NOT REGEXP',
    ];

    /**
     * @param mixed $value
     * @return static
     */
    public function where(string $field, string $operator, $value, ?callable $callback = null, string $type = 'and')
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

    /**
     * @param mixed $value
     * @return static
     */
    public function andWhere(string $field, string $operator, $value, ?callable $callback = null)
    {
        return $this->where($field, $operator, $value, $callback, 'and');
    }

    /**
     * @param mixed $value
     * @return static
     */
    public function and(string $field, string $operator, $value, ?callable $callback = null)
    {
        return $this->andWhere($field, $operator, $value, $callback);
    }

    /**
     * @param mixed $value
     * @return static
     */
    public function orWhere(string $field, string $operator, $value, ?callable $callback = null)
    {
        return $this->where($field, $operator, $value, $callback, 'or');
    }

    /**
     * @param mixed $value
     * @return static
     */
    public function or(string $field, string $operator, $value, ?callable $callback = null)
    {
        return $this->orWhere($field, $operator, $value, $callback);
    }

    /** @return static */
    public function whereIn(string $field, array $values, string $type = 'and')
    {
        return $this->where($field, 'IN', $values, null, $type);
    }

    /** @return static */
    public function whereNotIn(string $field, array $values, string $type = 'and')
    {
        return $this->where($field, 'NOT IN', $values, null, $type);
    }

    /** @return static */ public function andWhereIn(string $f, array $v)     { return $this->whereIn($f, $v, 'and'); }
    /** @return static */ public function andWhereNotIn(string $f, array $v)  { return $this->whereNotIn($f, $v, 'and'); }
    /** @return static */ public function orWhereIn(string $f, array $v)      { return $this->whereIn($f, $v, 'or'); }
    /** @return static */ public function orWhereNotIn(string $f, array $v)   { return $this->whereNotIn($f, $v, 'or'); }

    /** @return static */
    public function whereIsNull(string $field, string $type = 'and')
    {
        $this->commands['WHERE'][] = ['op' => $this->normalizeOp($type), 'expr' => $this->cleanReference($field) . ' IS NULL'];
        return $this;
    }

    /** @return static */
    public function whereIsNotNull(string $field, string $type = 'and')
    {
        $this->commands['WHERE'][] = ['op' => $this->normalizeOp($type), 'expr' => $this->cleanReference($field) . ' IS NOT NULL'];
        return $this;
    }

    /** @return static */ public function andWhereIsNull(string $f)     { return $this->whereIsNull($f, 'and'); }
    /** @return static */ public function andWhereIsNotNull(string $f)  { return $this->whereIsNotNull($f, 'and'); }
    /** @return static */ public function orWhereIsNull(string $f)      { return $this->whereIsNull($f, 'or'); }
    /** @return static */ public function orWhereIsNotNull(string $f)   { return $this->whereIsNotNull($f, 'or'); }

    private function assertWhereOperator(string $operator): void
    {
        $normalized = strtoupper($operator);
        $allowed    = array_map('strtoupper', self::WHERE_OPERATORS);
        if (!in_array($normalized, $allowed, true)) {
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
            // Subquery form: WHERE col IN (SELECT ...)
            if ($value instanceof Buildable) {
                [$sub_sql, $sub_bindings] = $value->toSql();
                foreach ($sub_bindings as $b) {
                    $this->bindings[] = $b;
                }
                return $field . ' ' . $operator . ' (' . $sub_sql . ')';
            }
            if (!is_array($value) || $value === []) {
                throw new \InvalidArgumentException("$operator requires a non-empty array or a Buildable subquery");
            }
            $placeholders = [];
            foreach ($value as $v) {
                $placeholders[]   = '?';
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
        // Raw on the value side emits verbatim (useful for
        // correlated subqueries referencing an outer alias).
        if ($value instanceof Raw) {
            return $field . ' ' . $operator . ' ' . $value->sql;
        }
        // Scalar subquery on the value side: SELECT that returns
        // exactly one row / column, e.g. `x = (SELECT MAX(y) FROM z)`.
        if ($value instanceof Buildable) {
            [$sub_sql, $sub_bindings] = $value->toSql();
            foreach ($sub_bindings as $b) {
                $this->bindings[] = $b;
            }
            return $field . ' ' . $operator . ' (' . $sub_sql . ')';
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
