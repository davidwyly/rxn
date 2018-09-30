<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Query\Where;
use Rxn\Orm\Builder\Table;

class Having extends Command
{
    /**
     * @param callable $callback
     *
     * @return $this
     */
    public function having(callable $callback)
    {
        call_user_func($callback, new Where());
        return $this;
    }
}
