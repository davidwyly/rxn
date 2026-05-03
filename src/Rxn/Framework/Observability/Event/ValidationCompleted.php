<?php declare(strict_types=1);

namespace Rxn\Framework\Observability\Event;

/**
 * Emitted by `Binder::bind()` after the DTO's validation rules
 * have all been checked. `$failures` is empty on success;
 * non-empty when one or more `#[Required]` / `#[Min]` / etc.
 * rules rejected the input — even though `bind()` would have
 * already thrown a `ValidationException` to its caller, the
 * event still fires so listeners can capture the failed field
 * names for metrics.
 *
 * This is the only framework event that fires *during* an
 * exception's bubbling — it's emitted from the binder's catch
 * block and re-throws.
 *
 * `$pairId` carries the request's pair id when the validation
 * happened inside an `App::serve()` request. Null when the
 * binder is driven outside a request scope.
 */
final class ValidationCompleted implements FrameworkEvent
{
    /**
     * @param class-string         $class
     * @param array<string, list<string>> $failures field → list of failure messages
     */
    public function __construct(
        public readonly string $class,
        public readonly array $failures,
        public readonly ?string $pairId = null,
    ) {}

    public function isFailure(): bool
    {
        return $this->failures !== [];
    }
}
