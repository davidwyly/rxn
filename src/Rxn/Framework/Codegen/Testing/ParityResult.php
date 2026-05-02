<?php declare(strict_types=1);

namespace Rxn\Framework\Codegen\Testing;

/**
 * Outcome of a `ParityHarness::run()`. Exposes the disagreement
 * count for the assertion plus a `describe()` method that
 * formats the first few samples for failure messages.
 */
final class ParityResult
{
    /**
     * @param list<array{input: array, php: list<string>, target: list<string>}> $samples
     */
    public function __construct(
        public readonly int $disagreements,
        public readonly int $iterations,
        public readonly array $samples,
    ) {}

    public function describe(): string
    {
        if ($this->disagreements === 0) {
            return "0 disagreements over {$this->iterations} inputs";
        }
        return "{$this->disagreements} / {$this->iterations} disagreements\n"
             . "First samples:\n"
             . json_encode($this->samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
