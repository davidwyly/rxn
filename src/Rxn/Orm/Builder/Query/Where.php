<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder\Command;

class Where extends Command
{

    const WHERE_COMMANDS = [
        'where' => 'WHERE',
        'and'   => 'WHERE',
        'or'    => 'OR',
    ];

    const WHERE_OPERATORS = [
        '=',
        '!=',
        '<>',
        'IN',
        'NOT IN',
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

    public function set(
        string $first_operand,
        string $operator,
        string $second_operand,
        callable $callback = null,
        string $type = 'where'
    ) {
        $this->validateType($type);
        if ($this->isReference($first_operand)
            && $this->isReference($second_operand)
        ) {
            $first_operand  = $this->cleanReference($first_operand);
            $second_operand = $this->cleanReference($second_operand);
        } elseif ($this->isReference($first_operand)
            && !$this->isReference($second_operand)
        ) {
            $first_operand = $this->cleanReference($first_operand);
            list($second_operand, $bindings) = $this->getOperandBindings($second_operand);
            $this->addBindings($bindings);
        } elseif (!$this->isReference($first_operand)
            && $this->isReference($second_operand)
        ) {
            list($first_operand, $bindings) = $this->getOperandBindings($first_operand);
            $this->addBindings($bindings);
            $second_operand = $this->cleanReference($second_operand);
        }
        $value   = "$first_operand $operator $second_operand";
        if (!is_null($callback)) {
            $command = self::WHERE_COMMANDS['where'];
            $this->addCommand($command, $value);
            call_user_func($callback, $this);
        } else {
            $command = self::WHERE_COMMANDS[$type];
            $this->addCommand($command, $value);
        }
    }

    public function setIn(
        string $operand,
        array $values,
        callable $callback = null,
        string $type = 'where',
        $not = false
    ) {
        $this->validateType($type);
        $operand  = $this->cleanReference($operand);
        $operator = ($not) ? 'NOT IN' : 'IN';
        list($value_list, $bindings) = $this->getOperandBindings($values);
        $value   = "$operand $operator $value_list";
        $command = self::WHERE_COMMANDS[$type];
        $this->addCommand($command, $value);
        $this->addBindings($bindings);
        if (!is_null($callback)) {
            call_user_func($callback, $this);
        }
    }

    public function setNull(string $operand, callable $callback = null, string $type = 'where', $not = false)
    {
        $this->validateType($type);
        $operand  = $this->cleanReference($operand);
        $operator = ($not) ? 'IS NOT NULL' : 'IS NULL';
        $value    = "$operand $operator";
        $command  = self::WHERE_COMMANDS[$type];
        $this->addCommand($command, $value);
        if (!is_null($callback)) {
            call_user_func($callback, $this);
        }
    }

    private function validateType($type)
    {
        if (!array_key_exists($type, self::WHERE_COMMANDS)) {
            throw new \Exception("");
        }
    }

    private function isReference($operand)
    {
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
