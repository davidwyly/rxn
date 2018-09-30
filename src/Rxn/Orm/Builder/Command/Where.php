<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Query;

class Where extends Command
{

    const WHERE_COMMANDS = [
        'where' => 'WHERE',
        'and'   => 'AND',
        'or'    => 'OR',
    ];

    const WHERE_OPERATORS = [
        '=',
        '!=',
        '<>',
        'LIKE',
        'NOT LIKE',
        'BETWEEN',
        'REGEXP',
        'NOT REGEXP',
        '<',
        '<=',
        '>',
        '>=',
    ];

    protected $command = 'WHERE';

    protected $operand_1;

    protected $operator;

    protected $operand_2;

    /**
     * @var Command[]
     */
    protected $commands;

    public function new()
    {
        $where            = new Where();
        $this->commands[] = $where;
        return $where;
    }

    public function and (callable $callback): Command
    {
        $where                 = new Where();
        $this->commands['AND'] = $where->unset();
        call_user_func($callback, $where);
        return $this;
    }

    public function or (callable $callback): Command
    {
        $where                = new Where();
        $this->commands['OR'] = $where->unset();
        call_user_func($callback, $where);
        return $this;
    }

    public function unset()
    {
        unset($this->command, $this->operand_1, $this->operator, $this->operand_2, $this->tables, $this->bindings);
        return $this;
    }

    /**
     * @param string $operand_1
     * @param string $operator
     * @param mixed  $operand_2
     *
     * @return $this
     * @throws \Exception
     */
    public function where(string $operand_1, string $operator, $operand_2): Where
    {
        $this->validateOperator($operator);

        if ($this->isReference($operand_1)) {
            $this->operand_1 = $this->cleanReference($operand_1);
        } else {
            list($this->operand_1, $bindings) = $this->getOperandBindings($operand_1);
            $this->addBindings($bindings);
        }

        $this->operator = $operator;

        if ($this->isReference($operand_2)) {
            $this->operand_2 = $this->cleanReference($operand_2);
        } else {
            list($this->operand_2, $bindings) = $this->getOperandBindings($operand_2);
            $this->addBindings($bindings);
        }
        return $this;
    }

    public function equals($operand_1, $operand_2)
    {
        return $this->new()->where($operand_1, '=', $operand_2);
    }

    public function greaterThan($operand_1, $operand_2)
    {
        return $this->new()->where($operand_1, '>', $operand_2);
    }

    public function greaterThanOrEquals($operand_1, $operand_2)
    {
        return $this->new()->where($operand_1, '>=', $operand_2);
    }

    public function lessThan($operand_1, $operand_2)
    {
        return $this->new()->where($operand_1, '<', $operand_2);
    }

    public function lessThanOrEquals($operand_1, $operand_2)
    {
        return $this->new()->where($operand_1, '<=', $operand_2);
    }

    public function between($operand, $date_1, $date_2)
    {
        return $this;
    }

    public function exists($operand, Query $query)
    {
        return $this; // TODO
    }

    public function notNull($operand)
    {
        return $this->null($operand, true);
    }

    public function in(string $operand, array $operands, $not = false): Where
    {
        $this->operand_1 = $this->cleanReference($operand);
        $this->operator  = ($not) ? 'NOT IN' : 'IN';

        $cleaned_operands = [];
        foreach ($operands as $key => $unclean_operand) {
            if ($this->isReference($unclean_operand)) {
                $cleaned_operands[$key] = $this->cleanReference($unclean_operand);
            } else {
                $cleaned_operands[$key] = $unclean_operand;
            }
        }
        $this->operand_2 = $cleaned_operands;
        return $this;
    }

    public function null(string $operand, $not = false): Where
    {
        $this->operand_1 = $this->cleanReference($operand);
        $this->operator  = ($not) ? 'IS NOT NULL' : 'IS NULL';
        return $this;
    }

    private function validateType($type)
    {
        if (!array_key_exists($type, self::WHERE_COMMANDS)) {
            throw new \Exception("'$type' is not a valid WHERE command");
        }
    }

    private function validateOperator($operator)
    {
        if (!in_array($operator, self::WHERE_OPERATORS)) {
            throw new \Exception("'$operator' is not a valid WHERE operator");
        }
    }

    private function isReference($operand): bool
    {
        if (!is_string($operand)) {
            return false;
        }
        $result = explode('.', $operand);
        if (isset($result[1])) {
            return true;
        }

        preg_match('#\`.+\`#', $operand, $matches);
        if (isset($matches[0])) {
            return true;
        }

        return false;
    }
}
