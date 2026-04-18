<?php declare(strict_types=1);

namespace Rxn\Framework\Data;

use Rxn\Framework\Data\Map\Chain\Link;

/**
 * Walks a Map and materializes the foreign-key graph between its
 * tables as a set of Link instances, then indexes them for the two
 * common query shapes:
 *
 *   - `belongsTo($table)`   — links pointing FROM $table to others
 *                              (i.e., $table has a FK column)
 *   - `hasMany($table)`     — links pointing TO $table from others
 */
class Chain
{
    /** @var Map */
    protected $map;

    /** @var Link[] */
    private $links = [];

    /** @var array<string, Link[]> keyed by from-table */
    private $by_from = [];

    /** @var array<string, Link[]> keyed by to-table */
    private $by_to = [];

    public function __construct(Map $map)
    {
        $this->map = $map;
        $this->validateMap();
        $this->buildLinks();
    }

    /**
     * @return Link[] every discovered FK edge
     */
    public function all(): array
    {
        return $this->links;
    }

    /**
     * Links pointing FROM $table. Tells you which tables $table
     * depends on (a 'belongs to' relation).
     *
     * @return Link[]
     */
    public function belongsTo(string $table): array
    {
        return $this->by_from[$table] ?? [];
    }

    /**
     * Links pointing TO $table. Tells you which other tables
     * reference $table (a 'has many' relation).
     *
     * @return Link[]
     */
    public function hasMany(string $table): array
    {
        return $this->by_to[$table] ?? [];
    }

    private function buildLinks(): void
    {
        $tables = $this->map->getTables();
        foreach ($tables as $from_name => $table) {
            foreach ($table->getFieldReferences() as $from_column => $ref) {
                $to_name = $ref['table'];
                // Skip relations to tables we don't know about.
                if (!array_key_exists($to_name, $tables)) {
                    continue;
                }
                $to_column = $ref['column'] !== ''
                    ? $ref['column']
                    : ($tables[$to_name]->getPrimaryKeys()[0] ?? '');
                if ($to_column === '') {
                    continue;
                }
                $link                      = new Link($from_name, $from_column, $to_name, $to_column);
                $this->links[]             = $link;
                $this->by_from[$from_name][] = $link;
                $this->by_to[$to_name][]     = $link;
            }
        }
    }

    private function validateMap(): void
    {
        if (empty($this->map->getTables())) {
            throw new \Exception('Chain requires a Map with at least one registered table', 500);
        }
    }
}
