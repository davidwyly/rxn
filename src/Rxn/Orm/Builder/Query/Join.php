<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Query\Join\On;
use Rxn\Orm\Builder\Table;

class Join extends Command
{
    const JOIN_COMMANDS = [
        'inner' => 'INNER JOIN',
        'left'  => 'LEFT JOIN',
        'right' => 'RIGHT JOIN',
    ];

    /**
     * @var
     */
    public $command;

    /**
     * @var Table[]
     */
    public $tables;

    /**
     * @var On[]|Where[]
     */
    public $commands;

    /**
     * @param string      $table
     * @param callable    $callable
     * @param string|null $alias
     * @param string      $type
     *
     * @return $this
     * @throws \Exception
     */
    public function join(string $table, callable $callable, string $alias = null, string $type = 'inner')
    {
        if (!array_key_exists($type, self::JOIN_COMMANDS)) {
            throw new \Exception("");
        }
        $this->command = self::JOIN_COMMANDS[$type];
        $this->addTable($table, $alias);
        call_user_func($callable, $this);
        return $this;
    }

    public function on(string $operand_1, string $operator, $operand_2)
    {
        $command          = new On($operand_1, $operator, $operand_2);
        $this->commands[] = $command;
        return $this;
    }

    public function where(
        string $operand_1,
        string $operator,
        string $operand_2,
        string $type = 'and',
        callable $callback = null
    ): Command {
        $this->commands[] = (new Where())->where($operand_1, $operator, $operand_2, $callback, $type);
        return $this;
    }

    public function whereIn(
        string $operand,
        array $operands,
        string $type = 'and',
        $not = false,
        callable $callback = null
    ) {
        $this->commands[] = (new Where())->in($operand, $operands, $callback, $type, $not);
        return $this;
    }

    public function whereIsNull(string $operand, $type = 'and', $not = false, callable $callback = null)
    {
        $this->commands[] = (new Where())->isNull($operand, $callback, $type, $not);
        return $this;
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }
}
