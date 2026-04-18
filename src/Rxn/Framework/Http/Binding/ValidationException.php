<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Binding;

use Rxn\Framework\Error\RequestException;

/**
 * Thrown by `Binder::bind()` when one or more DTO fields fail
 * casting or attribute validation. Carries the structured
 * `errors` list so it can surface as a Problem Details extension
 * member instead of collapsing into a single flat message.
 */
final class ValidationException extends RequestException
{
    /** @var list<array{field: string, message: string}> */
    private array $errors;

    /** @param list<array{field: string, message: string}> $errors */
    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422);
        $this->errors = $errors;
    }

    /** @return list<array{field: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }
}
