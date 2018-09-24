<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

class Join
{
    public $table;
    /**
     * @var array
     */
    public $commands;

    /**
     * @var
     */
    public $bindings;

    public function __construct($table) {
        $this->table = $table;
    }

    public function on(string $first, string $condition, $second) {
        $value = "`$first` $condition `$second`";
        $this->addCommand('ON', $value);
        return $this;
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }
}
