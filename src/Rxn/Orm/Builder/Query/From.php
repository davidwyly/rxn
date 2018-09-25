<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder\Query;

class From extends Query {

    public function fromClause(string $table, string $alias = null) {
        $escaped_table = $this->escapedReference($table);
        if (empty($alias)) {
            $value = $escaped_table;
        } else {
            $escaped_alias = $this->escapedReference($alias);
            $value         = "$escaped_table AS $escaped_alias";
        }
        $this->addCommand('FROM', $value);
    }
}
