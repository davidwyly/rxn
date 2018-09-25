<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder;

class Join extends Builder
{
    const JOIN_COMMANDS = [
        'inner' => 'INNER JOIN',
        'left'  => 'LEFT JOIN',
        'right' => 'RIGHT JOIN',
    ];

    /**
     * @var string
     */
    public $table;

    /**
     * @var string
     */
    public $alias;

    /**
     * @var
     */
    public $modifiers;

    /**
     * @var
     */
    public $bindings;

    public function joinClause(string $table, callable $callable, string $alias = null, string $type = 'inner') {
        if (!array_key_exists($type, self::JOIN_COMMANDS)) {
            throw new \Exception("");
        }
        $this->setAlias($alias);
        $command = self::JOIN_COMMANDS[$type];
        call_user_func($callable, $this);
        $this->addCommandWithModifiers($command, $this->modifiers, $table);
        $this->addBindings($this->bindings);
    }

    public function on(string $first, string $condition, $second) {
        $value = "`$first` $condition `$second`";
        $this->modifiers['ON'][] = $value;
        return $this;
    }

    private function setAlias($alias) {
        if (!empty($alias)) {
            $this->as($alias);
        }
    }

    private function as(string $alias) {
        $value = "`$alias`";
        $this->modifiers['AS'][] = $value;
        return $this;
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }
}
