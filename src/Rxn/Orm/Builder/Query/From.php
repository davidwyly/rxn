<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder\Command;

class From extends Command
{

    public function set(string $table, string $alias = null, $database = null)
    {
        $this->command = 'FROM';
        $this->addTable($table, $alias, $database);
        return $this;
    }
}
