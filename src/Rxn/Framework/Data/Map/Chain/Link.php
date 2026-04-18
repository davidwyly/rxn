<?php declare(strict_types=1);

namespace Rxn\Framework\Data\Map\Chain;

/**
 * Immutable value object representing a single foreign-key edge
 * between two tables, as discovered by schema reflection.
 *
 *   - `fromTable.fromColumn` references `toTable.toColumn`
 *
 * Chain produces Link instances while walking
 * information_schema.key_column_usage.
 */
final class Link
{
    public function __construct(
        public readonly string $fromTable,
        public readonly string $fromColumn,
        public readonly string $toTable,
        public readonly string $toColumn
    ) {
        if ($fromTable === '' || $fromColumn === '' || $toTable === '' || $toColumn === '') {
            throw new \InvalidArgumentException(
                'Link requires non-empty fromTable/fromColumn/toTable/toColumn'
            );
        }
    }

    /**
     * Deterministic string form useful as a map key or cache tag.
     */
    public function signature(): string
    {
        return $this->fromTable . '.' . $this->fromColumn
            . '->' . $this->toTable . '.' . $this->toColumn;
    }
}
