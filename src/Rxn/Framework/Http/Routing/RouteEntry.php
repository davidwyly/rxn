<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Routing;

/**
 * One reflected `#[Route]` attribute paired with the source
 * coordinates needed to point a developer at the offending method.
 *
 * The detector takes a list of these and runs a pairwise overlap
 * check; conflicts come back with both source files + line numbers.
 */
final class RouteEntry
{
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly string $class,
        public readonly string $methodName,
        public readonly string $file,
        public readonly int $line,
    ) {}

    public function describe(): string
    {
        return $this->method . ' ' . $this->pattern
            . "  (" . $this->class . '::' . $this->methodName . '() at '
            . $this->file . ':' . $this->line . ')';
    }
}
