<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Routing;

/**
 * A `#[Route]` whose pattern references a constraint type the
 * detector wasn't told about. The runtime `Router::compile()`
 * would throw `Unknown route constraint type` at registration —
 * the detector reports the same diagnostic at CI time so the
 * gate matches runtime semantics.
 *
 * Triggered by either a typo (`{id:nonsene}` instead of `:nonsense`)
 * or by a custom constraint registered at runtime that the
 * detector wasn't given via its `$constraints` constructor arg.
 */
final class InvalidRoute
{
    public function __construct(
        public readonly RouteEntry $entry,
        public readonly string $reason,
    ) {}

    public function describe(): string
    {
        return "Invalid route ($this->reason):\n  - " . $this->entry->describe();
    }
}
