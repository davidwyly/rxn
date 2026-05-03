<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Routing;

/**
 * One ambiguity between two registered routes — the kind that
 * makes whichever route was registered first silently win at
 * runtime, leaving the second as a dead route the user thinks
 * is wired up.
 *
 * The pair is unordered: `Conflict(A, B)` and `Conflict(B, A)`
 * mean the same thing. The detector emits each pair once.
 */
final class Conflict
{
    public function __construct(
        public readonly RouteEntry $a,
        public readonly RouteEntry $b,
        public readonly string $reason,
    ) {}

    public function describe(): string
    {
        return "Ambiguous routes ($this->reason):\n"
            . '  - ' . $this->a->describe() . "\n"
            . '  - ' . $this->b->describe();
    }
}
