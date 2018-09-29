<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder\Command;

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

    /**
     * @var string
     */
    protected $operand_1;

    /**
     * @var string
     */
    protected $operator;

    /**
     * @var string|array
     */
    protected $operand_2;

    /**
     * @var Command[]
     */
    protected $commands;

    /**
     * @param string        $operand_1
     * @param string        $operator
     * @param string        $operand_2
     * @param callable|null $callback
     * @param string        $type
     *
     * @return $this
     * @throws \Exception
     */
    public function where(
        string $operand_1,
        string $operator,
        string $operand_2,
        callable $callback = null,
        string $type = 'where'
    ): Where {
        $this->validateType($type);
        $this->validateOperator($operator);

        $this->command = self::WHERE_COMMANDS[$type];

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

        if (!is_null($callback)) {
            call_user_func($callback, $this);
        }
        return $this;
    }

    public function in(
        string $operand,
        array $operands,
        callable $callback = null,
        $type = 'where',
        $not = false
    ): Where {
        $this->validateType($type);
        $this->command   = self::WHERE_COMMANDS[$type];
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

        if (!is_null($callback)) {
            call_user_func($callback, $this);
        }
        return $this;
    }

    public function isNull(string $operand, callable $callback = null, $type = 'where', $not = false): Where
    {
        $this->validateType($type);
        $this->command   = self::WHERE_COMMANDS[$type];
        $this->operand_1 = $this->cleanReference($operand);
        $this->operator  = ($not) ? 'IS NOT NULL' : 'IS NULL';

        if (!is_null($callback)) {
            call_user_func($callback, $this);
        }
        return $this;
    }

    public function and (string $operand_1, string $operator, string $operand_2, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->where($operand_1, $operator, $operand_2, $callback, 'and');
        return $this;
    }

    public function andIn(string $operand, array $operands, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->in($operand, $operands, $callback, 'and', false);
        return $this;
    }

    public function andNotIn(string $operand, array $operands, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->in($operand, $operands, $callback, 'and', true);
        return $this;
    }

    public function andIsNull(string $operand, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->isNull($operand, $callback, 'and', false);
        return $this;
    }

    public function andIsNotNull(string $operand, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->isNull($operand, $callback, 'and', true);
        return $this;
    }

    public function or (string $operand_1, string $operator, string $operand_2, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->where($operand_1, $operator, $operand_2, $callback, 'or');
        return $this;
    }

    public function orIn(string $operand, array $operands, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->in($operand, $operands, $callback, 'or', false);
        return $this;
    }

    public function orNotIn(string $operand, array $operands, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->in($operand, $operands, $callback, 'or', true);
        return $this;
    }

    public function orIsNull(string $operand, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->isNull($operand, $callback, 'or', false);
        return $this;
    }

    public function orIsNotNull(string $operand, callable $callback = null): Where
    {
        $this->commands[] = (new Where())->isNull($operand, $callback, 'or', true);
        return $this;
    }

    private function validateType($type)
    {
        if (!array_key_exists($type, self::WHERE_COMMANDS)) {
            throw new \Exception("'$type' is not a valid WHERE command");
        }
    }

    private function validateOperator($operator) {
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
