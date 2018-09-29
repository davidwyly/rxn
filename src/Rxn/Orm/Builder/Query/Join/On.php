<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query\Join;

use Rxn\Orm\Builder\Command;

class On extends Command
{
    public $command = 'ON';

    public $first_operand;
    public $operator;
    public $second_operand;

    public function __construct(string $first_operand, $operator, $second_operand)
    {
        $this->first_operand  = $first_operand;
        $this->operator       = $operator;
        $this->second_operand = $second_operand;
        $this->addColumn($first_operand);
        $this->addColumn($second_operand);
    }
}
