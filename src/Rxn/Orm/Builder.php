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
     * @param string $string
     *
     * @return string
     */
    protected function filterString(string $string): string
    {
        $string = preg_replace('#[\`\s]#','',$string);
        preg_match('#[\p{L}\_\.\-\`]+#', $string, $matches);
        if (isset($matches[0])) {
            return $matches[0];
        }
        return '';
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

    protected function loadCommands(Builder $builder) {
        $this->commands = array_merge($this->commands, $builder->commands);
    }

    protected function loadBindings(Builder $builder) {
        $this->bindings = array_merge($this->bindings, $builder->bindings);
    }

    protected function getCommands() {
        return $this->commands;
    }

    protected function addBindings($key_values)
    {
        if (empty($key_values)) {
            return false;
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

}
