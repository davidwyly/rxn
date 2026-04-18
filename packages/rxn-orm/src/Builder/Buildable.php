<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

/**
 * Contract for anything that produces an executable SQL statement.
 * Implemented by Query (SELECT), Insert, Update, and Delete; passed
 * to Database::run for end-to-end execution.
 */
interface Buildable
{
    /**
     * @return array{0: string, 1: array} [sql, positional bindings]
     */
    public function toSql(): array;
}
