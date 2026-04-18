<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;

/**
 * Walks a Builder's command tree and produces a single-line SQL
 * string. Bindings live on the Builder itself and are surfaced by
 * Query::toSql(); this class is purely about shape.
 */
class QueryParser
{
    private Builder $builder;

    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
    }

    public function getSql(): string
    {
        $parts = array_filter([
            $this->select(),
            $this->from(),
            $this->join('INNER JOIN'),
            $this->join('LEFT JOIN'),
            $this->join('RIGHT JOIN'),
            $this->where(),
            $this->groupBy(),
            $this->having(),
            $this->orderBy(),
            $this->limit(),
            $this->offset(),
        ]);
        return implode(' ', $parts);
    }

    private function commands(string $key): ?array
    {
        return $this->builder->commands[$key] ?? null;
    }

    private function select(): string
    {
        $cols = $this->commands('SELECT') ?? $this->commands('SELECT DISTINCT');
        if ($cols === null) {
            return '';
        }
        $prefix = isset($this->builder->commands['SELECT DISTINCT']) ? 'SELECT DISTINCT' : 'SELECT';
        if ($cols === ['*']) {
            return "$prefix *";
        }
        return $prefix . ' ' . implode(', ', $cols);
    }

    private function from(): string
    {
        $from = $this->commands('FROM');
        return $from === null ? '' : 'FROM ' . implode(', ', $from);
    }

    private function join(string $command): string
    {
        $joins = $this->commands($command);
        if ($joins === null) {
            return '';
        }
        $parts = [];
        foreach ($joins as $table => $modifiers) {
            $escaped = '`' . $table . '`';
            if (!empty($modifiers['AS'])) {
                $escaped .= ' AS ' . $modifiers['AS'][0];
            }
            $segment = $command . ' ' . $escaped;
            if (!empty($modifiers['ON'])) {
                $segment .= ' ON ' . implode(' AND ', $modifiers['ON']);
            }
            $parts[] = $segment;
        }
        return implode(' ', $parts);
    }

    /**
     * Exposed so Update / Delete can reuse the WHERE rendering
     * without duplicating renderConditions.
     */
    public function whereSql(): string
    {
        $wheres = $this->commands('WHERE');
        if ($wheres === null || $wheres === []) {
            return '';
        }
        return 'WHERE ' . $this->renderConditions($wheres);
    }

    private function where(): string
    {
        return $this->whereSql();
    }

    /**
     * @param array<int, array{op?: string, expr?: string, group?: array}> $conditions
     */
    private function renderConditions(array $conditions): string
    {
        $parts = [];
        $first = true;
        foreach ($conditions as $entry) {
            // Back-compat: old code paths might still push a bare string.
            if (is_string($entry)) {
                $entry = ['op' => 'AND', 'expr' => $entry];
            }
            $prefix = $first ? '' : (($entry['op'] ?? 'AND') . ' ');
            $first  = false;
            if (isset($entry['group'])) {
                $parts[] = $prefix . '(' . $this->renderConditions($entry['group']) . ')';
            } else {
                $parts[] = $prefix . ($entry['expr'] ?? '');
            }
        }
        return implode(' ', $parts);
    }

    private function groupBy(): string
    {
        $cols = $this->commands('GROUP BY');
        return $cols === null ? '' : 'GROUP BY ' . implode(', ', $cols);
    }

    private function having(): string
    {
        $cond = $this->commands('HAVING');
        return $cond === null ? '' : 'HAVING ' . implode(' AND ', $cond);
    }

    private function orderBy(): string
    {
        $cols = $this->commands('ORDER BY');
        return $cols === null ? '' : 'ORDER BY ' . implode(', ', $cols);
    }

    private function limit(): string
    {
        $limit = $this->commands('LIMIT');
        return $limit === null ? '' : 'LIMIT ' . (int)$limit[0];
    }

    private function offset(): string
    {
        $offset = $this->commands('OFFSET');
        return $offset === null ? '' : 'OFFSET ' . (int)$offset[0];
    }
}
