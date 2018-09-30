<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Command;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Command\Where;
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

    public function equals(string $operand_1, $operand_2)
    {
        $this->commands[] = (new Where())->equals($operand_1, $operand_2);
        return $this;
    }

    public function where() {
        $where = new Where();
        $this->commands[] = $where;
        return $where;
    }

    public function whereIn(
        string $operand,
        array $operands,
        string $type = 'and',
        $not = false,
        callable $callback = null
    ) {
        $this->commands[] = (new Where())->in($operand, $operands, $not);
        return $this;
    }

    public function whereIsNull(string $operand, $type = 'and', $not = false, callable $callback = null)
    {
        $this->commands[] = (new Where())->null($operand, $not);
        return $this;
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }
}
