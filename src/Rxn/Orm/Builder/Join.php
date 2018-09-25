<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;

class Join extends Builder
{
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
    public $bindings;

    public function __construct($table, $alias) {
        $this->table = $table;
        $this->alias = $alias;
        $this->setAlias();
    }

    public function on(string $first, string $condition, $second) {
        $value = "`$first` $condition `$second`";
        $this->addCommand('ON', $value);
        return $this;
    }

    private function setAlias() {
        if (!empty($this->alias)) {
            $this->as($this->alias);
        }
    }

    private function as(string $alias) {
        $value = "`$alias`";
        $this->addCommand('AS', $value);
        return $this;
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }
}
