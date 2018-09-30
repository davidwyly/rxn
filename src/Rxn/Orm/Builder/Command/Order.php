<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Query\Where;
use Rxn\Orm\Builder\Table;

class Order extends Command
{
    public function orderBy(array $columns)
    {
        return $this;
    }
}
