<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Routing;

/**
 * Bundle of findings the conflict detector emits for a controller
 * set: pairwise ambiguities (`Conflict`) AND routes that wouldn't
 * even register at runtime (`InvalidRoute`). The CI gate fails on
 * either, but the user sees both at once instead of fixing one,
 * rerunning, fixing the next, etc.
 */
final class DetectorResult
{
    /**
     * @param list<InvalidRoute> $invalid
     * @param list<Conflict>     $conflicts
     */
    public function __construct(
        public readonly array $invalid,
        public readonly array $conflicts,
    ) {}

    public function isClean(): bool
    {
        return $this->invalid === [] && $this->conflicts === [];
    }

    public function describe(): string
    {
        if ($this->isClean()) {
            return "No route conflicts.\n";
        }
        $out = '';
        if ($this->invalid !== []) {
            $out .= 'Found ' . count($this->invalid) . " invalid route(s):\n\n";
            foreach ($this->invalid as $i) {
                $out .= $i->describe() . "\n\n";
            }
        }
        if ($this->conflicts !== []) {
            $out .= 'Found ' . count($this->conflicts) . " route conflict(s):\n\n";
            foreach ($this->conflicts as $c) {
                $out .= $c->describe() . "\n\n";
            }
            $out .= "Each pair is a runtime-silent ambiguity — whichever route\n";
            $out .= "was registered first wins; the other is dead code.\n";
        }
        return $out;
    }
}
