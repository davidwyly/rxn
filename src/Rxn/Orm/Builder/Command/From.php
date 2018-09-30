<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;

class From extends Command
{

    public function from(string $table, string $alias = null, $database = null)
    {
        $this->command = 'FROM';
        $this->addTable($table, $alias, $database);
        return $this;
    }
}
