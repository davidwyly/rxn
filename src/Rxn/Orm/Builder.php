<?php declare(strict_types=1);

namespace Rxn\Orm;

abstract class Builder
{
    /**
     * @var array
     */
    public $commands = [];

    /**
     * @var array
     */
    public $bindings = [];

    /**
     * @var array
     */
    public $table_aliases = [];

    /**
     * @param string $reference
     *
     * @return string
     */
    protected function cleanReference(string $reference): string
    {
        $filtered_reference = $this->filterReference($reference);
        return $this->escapeReference($filtered_reference);
    }

    protected function cleanValue(string $value): string
    {
        return "'$value'";
    }

    function changeKey(&$array, $old_key, $new_key)
    {
        if (!array_key_exists($old_key, $array)) {
            return $array;
        }
        $keys                                = array_keys($array);
        $keys[array_search($old_key, $keys)] = $new_key;
        return array_combine($keys, $array);
    }

    /**
     * @param string $operand
     *
     * @return string
     */
    protected function filterReference(string $operand): string
    {
        $operand = preg_replace('#[\`\s]#', '', $operand);
        preg_match('#[\p{L}\_\.\-\`0-9]+#', $operand, $matches);
        if (isset($matches[0])) {
            return $matches[0];
        }
        return '';
    }

    /**
     * @param string $operand
     *
     * @return string
     */
    protected function escapeReference(string $operand): string
    {
        $exploded_operand = explode('.', $operand);
        if (count($exploded_operand) === 2) {
            return "`{$exploded_operand[0]}`.`{$exploded_operand[1]}`";
        }
        return "`$operand`";
    }


    protected function isAssociative(array $array)
    {
        if ([] === $array) {
            return false;
        }
        ksort($array);
        return array_keys($array) !== range(0, count($array) - 1);
    }

    protected function addCommandWithModifiers($command, $modifiers, $key)
    {
        $this->commands[$command][$key] = $modifiers;
    }

    protected function addCommand($command, $value)
    {
        $this->commands[$command][] = $value;
    }

    protected function loadCommands(Builder $builder)
    {
        $this->commands = array_merge_recursive((array)$this->commands, (array)$builder->commands);
    }

    protected function loadGroupCommands(Builder $builder, $type) {
        $this->commands[$type][] = $builder->commands;
    }

    protected function loadBindings(Builder $builder)
    {
        $this->bindings = array_merge((array)$this->bindings, (array)$builder->bindings);
    }

    protected function loadTableAliases(Builder $builder)
    {
        $this->table_aliases = array_merge((array)$this->table_aliases, (array)$builder->table_aliases);
    }

    protected function getCommands()
    {
        return $this->commands;
    }

    protected function addBindings($key_values)
    {
        if (empty($key_values)) {
            return null;
        }
        foreach ($key_values as $value) {
            $this->addBinding($value);
        }
    }

    protected function addBinding($value)
    {
        $this->bindings[] = $value;
    }

    protected function getOperandBindings($operand): array
    {
        if (is_array($operand)) {
            $bindings     = [];
            $parsed_array = [];
            if (empty($bindings)) {
                foreach ($operand as $value) {
                    $parsed_array[] = '?';
                    $bindings[]     = $value;
                }
                return ['(' . implode(",", $parsed_array) . ')', $bindings];
            }
        }

        return ['?', [$operand]];
    }

    public function parseCommandAliases()
    {
        foreach ($this->commands as $command_type => $command_details) {
            switch ($command_type) {

                case 'FROM':
                    // do nothing
                    break;

                case 'INNER JOIN':
                    // no break
                case 'LEFT JOIN':
                    // no break
                case 'RIGHT JOIN':
                    // no break
                case 'CROSS JOIN':
                    // no break
                case 'NATURAL JOIN':
                    $this->parseJoinAliases($command_details, $command_type);
                    break;

                default:
                    $this->parseAliases($command_details, $command_type);
            }
        }
    }

    private function parseAliases(array $command_details, $command_type)
    {
        foreach ($command_details as $key => $value) {
            if ($value == '*') {
                break;
            }
            foreach ($this->table_aliases as $table => $alias) {
                $new_value = str_replace("`$table`", "`$alias`", $value);
                if ($new_value != $value) {
                    $this->commands[$command_type][$key] = $new_value;
                }
            }
        }
    }

    private function parseJoinAliases(array $command_details, $command_type)
    {
        foreach ($command_details as $command_table => $table_commands) {
            foreach ((array)$table_commands['ON'] as $key => $value) {
                foreach ($this->table_aliases as $table => $alias) {
                    $new_value = str_replace("`$table`", "`$alias`", $value);
                    if ($new_value != $value) {
                        $value                                                     = $new_value;
                        $this->commands[$command_type][$command_table]['ON'][$key] = $value;
                    }
                }
            }
            if (isset($table_commands['WHERE'])) {
                foreach ($table_commands['WHERE'] as $key => $value) {
                    foreach ($this->table_aliases as $table => $alias) {
                        $new_value = str_replace("`$table`", "`$alias`", $value);
                        if ($new_value != $value) {
                            $value                                                        = $new_value;
                            $this->commands[$command_type][$command_table]['WHERE'][$key] = $value;
                        }
                    }
                }
            }
        }
    }

}
