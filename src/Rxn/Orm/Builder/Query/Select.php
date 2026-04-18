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

        $command = $distinct ? 'SELECT DISTINCT' : 'SELECT';
        foreach ($columns as $key => $value) {
            if (is_int($key)) {
                if ($value instanceof Raw) {
                    $this->addCommand($command, $value->sql);
                    continue;
                }
                $this->emitNumerical($command, (string)$value);
                continue;
            }
            $this->emitAssociative($command, (string)$key, $value);
        }
    }

    public function selectAll(bool $distinct = false): void
    {
        $command = $distinct ? 'SELECT DISTINCT' : 'SELECT';
        $this->addCommand($command, '*');
    }

    /**
     * Handle a numeric-indexed column entry: a plain identifier,
     * an "identifier AS alias" clause, or a comma-delimited list
     * of either.
     */
    private function emitNumerical(string $command, string $entry): void
    {
        $clauses = preg_split('#\s*,\s*#', trim($entry), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($clauses as $clause) {
            $splits = preg_split('#\s+[aA][sS]\s+#', $clause);
            if (count($splits) === 2) {
                $reference = $this->cleanReference(array_shift($splits));
                $alias     = $this->cleanReference(array_shift($splits));
                $this->addCommand($command, "$reference AS $alias");
                continue;
            }
            $this->addCommand($command, $this->cleanReference($clause));
        }
    }

    /**
     * @param string|Raw|null $alias
     */
    private function emitAssociative(string $command, string $reference, $alias): void
    {
        $reference = $this->cleanReference($reference);
        if ($alias === null || $alias === '') {
            $this->addCommand($command, $reference);
            return;
        }
        $aliasSql = $alias instanceof Raw ? $alias->sql : $this->cleanReference((string)$alias);
        $this->addCommand($command, "$reference AS $aliasSql");
    }
}
