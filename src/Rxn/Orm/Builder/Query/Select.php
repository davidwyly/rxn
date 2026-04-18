<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Raw;

class Select extends Query
{
    public function set(array $columns = ['*'], bool $distinct = false): void
    {
        if ($columns === ['*'] || $columns === []) {
            $this->selectAll($distinct);
            return;
        }

        // Raw entries in numerical position pass through verbatim.
        // Split them off first, then reindex so the associative-vs-
        // numerical detection below isn't fooled by the holes left
        // behind.
        $rawEntries = [];
        $remaining  = [];
        foreach ($columns as $key => $value) {
            if ($value instanceof Raw && is_int($key)) {
                $rawEntries[] = $value->sql;
                continue;
            }
            $remaining[$key] = $value;
        }
        $wasNumerical = array_keys($remaining) === array_values(array_filter(array_keys($remaining), 'is_int'));
        if ($wasNumerical) {
            $remaining = array_values($remaining);
        }

        $command = $distinct ? 'SELECT DISTINCT' : 'SELECT';
        foreach ($rawEntries as $raw) {
            $this->addCommand($command, $raw);
        }

        if ($remaining === []) {
            return;
        }
        if ($this->isAssociative($remaining)) {
            $this->selectAssociative($remaining, $distinct);
        } else {
            $this->selectNumerical($remaining, $distinct);
        }
    }

    public function selectAll(bool $distinct = false): void
    {
        $command = $distinct ? 'SELECT DISTINCT' : 'SELECT';
        $this->addCommand($command, '*');
    }

    public function selectAssociative(array $columns, bool $distinct = false): void
    {
        $command = $distinct ? 'SELECT DISTINCT' : 'SELECT';
        foreach ($columns as $reference => $alias) {
            $reference = $this->cleanReference((string)$reference);
            if ($alias === null || $alias === '') {
                $this->addCommand($command, $reference);
                continue;
            }
            $aliasSql = $alias instanceof Raw ? $alias->sql : $this->cleanReference((string)$alias);
            $this->addCommand($command, "$reference AS $aliasSql");
        }
    }

    public function selectNumerical(array $numerical_columns, bool $distinct = false): void
    {
        $associative_columns = [];
        foreach ($numerical_columns as $key => $numerical_column) {
            if ($numerical_column instanceof Raw) {
                // Already emitted by Select::set via the raw pass.
                continue;
            }
            $numerical_column = trim((string)$numerical_column);
            $clauses          = preg_split('#(\s*\,\s*)+#', $numerical_column);
            if (count($clauses) > 1) {
                $clauses = array_reverse($clauses);
                unset($numerical_columns[$key]);
                foreach ($clauses as $clause) {
                    array_unshift($numerical_columns, $clause);
                }
            }
        }
        foreach ($numerical_columns as $key => $numerical_column) {
            if ($numerical_column instanceof Raw) {
                continue;
            }
            $splits_in_clause = preg_split('#\s+[aA][sS]\s+#', (string)$numerical_column);
            if (count($splits_in_clause) === 2) {
                unset($numerical_columns[$key]);
                $reference = array_shift($splits_in_clause);
                $alias     = array_shift($splits_in_clause);
                $associative_columns[$reference] = $alias;
            }
        }
        foreach ($numerical_columns as $column) {
            if ($column instanceof Raw) {
                continue;
            }
            $associative_columns[$column] = null;
        }
        if ($associative_columns !== []) {
            $this->selectAssociative($associative_columns, $distinct);
        }
    }
}
