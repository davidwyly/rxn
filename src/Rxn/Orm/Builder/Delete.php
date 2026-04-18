<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;

/**
 * Fluent DELETE builder. Uses the same WHERE machinery as Query
 * via HasWhere so AND / OR / grouped callbacks work identically.
 *
 *   [$sql, $bindings] = (new Delete())
 *       ->from('users')
 *       ->where('id', '=', 42)
 *       ->toSql();
 *   // DELETE FROM `users` WHERE `id` = ?
 *
 *   $database->run($delete);
 *
 * Safety: toSql() throws if no WHERE has been added. A "delete
 * every row in the table" statement must be expressed explicitly
 * via `->allowEmptyWhere()`.
 */
final class Delete extends Builder implements Buildable
{
    use HasWhere;

    private ?string $table = null;
    private bool    $allow_empty_where = false;

    /** @var string[] */
    private array $returning = [];

    public function from(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Permit `DELETE FROM t` without a WHERE clause. Off by default
     * so accidental truncate-the-table deletes aren't one
     * forgotten-line away.
     */
    public function allowEmptyWhere(bool $allow = true): self
    {
        $this->allow_empty_where = $allow;
        return $this;
    }

    /**
     * Append a RETURNING clause (PostgreSQL / SQLite).
     *
     * @param string|Raw ...$columns
     */
    public function returning(...$columns): self
    {
        foreach ($columns as $col) {
            $this->returning[] = $col instanceof Raw ? $col->sql : '`' . trim((string)$col, '`') . '`';
        }
        return $this;
    }

    public function toSql(): array
    {
        if ($this->table === null) {
            throw new \LogicException('Delete::from must be called before toSql');
        }
        $hasWhere = !empty($this->commands['WHERE']);
        if (!$hasWhere && !$this->allow_empty_where) {
            throw new \LogicException(
                'Delete with no WHERE clause is blocked; call allowEmptyWhere() to opt in'
            );
        }

        $sql       = 'DELETE FROM `' . trim($this->table, '`') . '`';
        $whereSql  = (new QueryParser($this))->whereSql();
        if ($whereSql !== '') {
            $sql .= ' ' . $whereSql;
        }

        if ($this->returning !== []) {
            $sql .= ' RETURNING ' . implode(', ', $this->returning);
        }

        return [$sql, $this->bindings];
    }
}
