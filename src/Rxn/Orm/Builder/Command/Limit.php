<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Query\Where;
use Rxn\Orm\Builder\Table;

class Limit extends Command
{
    public function limit(int $limit)
    {
        return $this;
    }
}
