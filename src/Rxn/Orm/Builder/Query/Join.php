<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder\Command;
use Rxn\Orm\Builder\Table;

class Join extends Command
{
    const JOIN_COMMANDS = [
        'inner' => 'INNER JOIN',
        'left'  => 'LEFT JOIN',
        'right' => 'RIGHT JOIN',
    ];

    /**
     * @var Table[]
     */
    public $tables;

    /**
     * @var
     */
    public $command;

    /**
     * @var On[]|Where[]
     */
    public $commands;

    public function set(string $table, callable $callable, string $alias = null, string $type = 'inner') {
        if (!array_key_exists($type, self::JOIN_COMMANDS)) {
            throw new \Exception("");
        }
        $this->command = self::JOIN_COMMANDS[$type];
        $this->addTable($table,$alias);
        call_user_func($callable, $this);
    }

    public function on(string $first_operand, string $operator, $second_operand) {
        $command = new On($first_operand, $operator, $second_operand);
        $this->commands[] = $command;
        return $this;
    }

    public function where(string $first_operand, string $operator, $second_operand) {
        $command = new Where();
        $command->set($first_operand,$operator,$second_operand);
        $this->commands[] = $command;
        return $this;
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }
}
