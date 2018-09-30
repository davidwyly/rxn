<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Query\Where;
use Rxn\Orm\Builder\Table;

class Group extends Command
{
    public function groupBy(array $columns)
    {
        return $this;
    }
}
