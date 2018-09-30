<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Query\Where;
use Rxn\Orm\Builder\Table;

class Offset extends Command
{
    public function offset(int $offset)
    {
        return $this;
    }
}
