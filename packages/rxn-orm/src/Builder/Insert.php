<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;

/**
 * Fluent INSERT builder. Supports single-row and multi-row inserts;
 * the column list is the union of every row's keys (first-seen
 * order), and rows that omit a column are bound as null so callers
 * don't have to pre-normalise heterogeneous inputs.
 *
 *   [$sql, $bindings] = (new Insert())
 *       ->into('users')
 *       ->row(['email' => 'a@example.com', 'role' => 'admin'])
 *       ->row(['email' => 'b@example.com', 'role' => 'member'])
 *       ->toSql();
 *   // INSERT INTO `users` (`email`, `role`) VALUES (?, ?), (?, ?)
 *
 *   $database->run($insert);
 *
 * Raw values in a row emit verbatim without a placeholder:
 *   $insert->row(['email' => 'x', 'created_at' => Raw::of('NOW()')]);
 */
final class Insert extends Builder implements Buildable
{
    private ?string $table = null;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /** @var array<string, mixed>|null */
    private ?array $on_duplicate = null;

    /** @var string[] */
    private array $returning = [];

    public function into(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * MySQL upsert: follow the INSERT with
     *   ON DUPLICATE KEY UPDATE col = val, ...
     * Values participate in the bindings stream after the row
     * placeholders. Raw values (e.g. Raw::of('VALUES(col)')) emit
     * verbatim, matching the INSERT helper API.
     *
     * @param array<string, mixed> $assignments
     */
    public function onDuplicateKeyUpdate(array $assignments): self
    {
        if ($assignments === []) {
            throw new \InvalidArgumentException('onDuplicateKeyUpdate requires at least one assignment');
        }
        $this->on_duplicate = $assignments;
        return $this;
    }

    /**
     * Append a RETURNING clause (PostgreSQL / SQLite). MySQL will
     * reject the statement; callers are responsible for knowing
     * their driver supports it.
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

    /**
     * @param array<string, mixed> $row
     */
    public function row(array $row): self
    {
        if ($row === []) {
            throw new \InvalidArgumentException('Insert::row requires a non-empty [column => value] map');
        }
        $this->rows[] = $row;
        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function rows(array $rows): self
    {
        foreach ($rows as $row) {
            $this->row($row);
        }
        return $this;
    }

    public function toSql(): array
    {
        if ($this->table === null) {
            throw new \LogicException('Insert::into must be called before toSql');
        }
        if ($this->rows === []) {
            throw new \LogicException('Insert requires at least one row');
        }

        // Union of column names across every row, first-seen order.
        $columns = [];
        foreach ($this->rows as $row) {
            foreach ($row as $col => $_) {
                if (!in_array($col, $columns, true)) {
                    $columns[] = $col;
                }
            }
        }

        $escapedTable   = '`' . trim($this->table, '`') . '`';
        $escapedColumns = array_map(fn ($c) => '`' . trim((string)$c, '`') . '`', $columns);

        $valueRows = [];
        $bindings  = [];
        foreach ($this->rows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                if ($value instanceof Raw) {
                    $placeholders[] = $value->sql;
                    continue;
                }
                $placeholders[] = '?';
                $bindings[]     = $value;
            }
            $valueRows[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = 'INSERT INTO ' . $escapedTable
             . ' (' . implode(', ', $escapedColumns) . ')'
             . ' VALUES ' . implode(', ', $valueRows);

        if ($this->on_duplicate !== null) {
            $assignments = [];
            foreach ($this->on_duplicate as $col => $value) {
                $escapedCol = '`' . trim((string)$col, '`') . '`';
                if ($value instanceof Raw) {
                    $assignments[] = "$escapedCol = " . $value->sql;
                    continue;
                }
                $assignments[] = "$escapedCol = ?";
                $bindings[]    = $value;
            }
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
        }

        if ($this->returning !== []) {
            $sql .= ' RETURNING ' . implode(', ', $this->returning);
        }

        return [$sql, $bindings];
    }
}
