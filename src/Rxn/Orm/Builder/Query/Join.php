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
        $this->table = $table;
        $this->addAlias($alias);
        $command = self::JOIN_COMMANDS[$type];
        call_user_func($callable, $this);
        $this->addBindings($this->bindings);
        $this->addCommandWithModifiers($command, $this->modifiers, $table);

    }

    public function on(string $first, string $condition, $second) {
        $first = $this->cleanReference($first);
        $second = $this->cleanReference($second);
        $value = "$first $condition $second";
        $this->modifiers['ON'][] = $value;
        return $this;
    }

    public function as(string $alias) {
        $this->alias = $alias;
        $clean_alias = $this->cleanReference($alias);
        if (!in_array($clean_alias, (array)$this->modifiers['AS'])) {
            $this->modifiers['AS'][]           = $clean_alias;
            $this->table_aliases[$this->table] = $alias;
        }
        return $this;
    }

    private function addAlias($alias) {
        if (!empty($alias)) {
            $this->as($alias);
        }
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }
}
