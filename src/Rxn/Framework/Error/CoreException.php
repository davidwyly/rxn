<?php declare(strict_types=1);

namespace Rxn\Framework\Error;

/**
 * Base framework exception. Subclasses are bare markers used to
 * discriminate on in catch blocks; the only behaviour this class
 * adds over \Exception is defaulting $code to 500 so internal
 * errors surface as HTTP 500 without every caller having to repeat
 * the literal.
 */
class CoreException extends \Exception
{
    public function __construct(string $message, int $code = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
