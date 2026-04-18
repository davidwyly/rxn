<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;

/**
 * Fluent UPDATE builder. Uses the same WHERE machinery as Query via
 * HasWhere so AND / OR / grouped callbacks work identically.
 *
 *   [$sql, $bindings] = (new Update())
 *       ->table('users')
 *       ->set(['role' => 'admin', 'active' => 1])
 *       ->where('id', '=', 42)
 *       ->toSql();
 *   // UPDATE `users` SET `role` = ?, `active` = ? WHERE `id` = ?
 *   // bindings: ['admin', 1, 42]
 *
 *   $database->run($update);
 *
 * Raw values in set() emit verbatim:
 *   $update->set(['updated_at' => Raw::of('NOW()')]);
 */
final class Update extends Builder implements Buildable
{
    use HasWhere;

    private ?string $table = null;

    /** @var array<string, mixed> */
    private array $set = [];

    /** @var string[] */
    private array $returning = [];

    public function table(string $table): self
    {
        $this->table = $table;
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

    /**
     * Merge column => value pairs into the SET clause. Called more
     * than once, later keys overwrite earlier ones.
     *
     * @param array<string, mixed> $assignments
     */
    public function set(array $assignments): self
    {
        foreach ($assignments as $col => $value) {
            $this->set[$col] = $value;
        }
        return $this;
    }

    public function toSql(): array
    {
        if ($this->table === null) {
            throw new \LogicException('Update::table must be called before toSql');
        }
        if ($this->set === []) {
            throw new \LogicException('Update requires at least one set() assignment');
        }

        $setBindings   = [];
        $setAssignments = [];
        foreach ($this->set as $col => $value) {
            $escaped = '`' . trim((string)$col, '`') . '`';
            if ($value instanceof Raw) {
                $setAssignments[] = "$escaped = " . $value->sql;
                continue;
            }
            $setAssignments[] = "$escaped = ?";
            $setBindings[]    = $value;
        }

        $sql = 'UPDATE `' . trim($this->table, '`') . '`'
             . ' SET ' . implode(', ', $setAssignments);

        // HasWhere appends placeholders to $this->bindings as
        // conditions are added; those must land *after* the SET
        // bindings in the final positional-binding list.
        $whereBindings = $this->bindings;
        $whereSql      = (new QueryParser($this))->whereSql();
        if ($whereSql !== '') {
            $sql .= ' ' . $whereSql;
        }

        if ($this->returning !== []) {
            $sql .= ' RETURNING ' . implode(', ', $this->returning);
        }

        return [$sql, array_merge($setBindings, $whereBindings)];
    }
}
