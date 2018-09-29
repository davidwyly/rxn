<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query\Join;

use Rxn\Orm\Builder\Command;

class On extends Command
{
    public $command = 'ON';

    public $operand_1;
    public $operator;
    public $operand_2;

    public function __construct(string $operand_1, $operator, $operand_2)
    {
        $this->operand_1  = $operand_1;
        $this->operator       = $operator;
        $this->operand_2 = $operand_2;
        $this->addColumn($operand_1);
        $this->addColumn($operand_2);
    }
}
