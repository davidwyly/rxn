<?php declare(strict_types=1);

namespace Rxn\Framework\Utility;

/**
 * Placeholder for the mailer utility advertised in the README.
 * Not yet implemented; instantiating this class will fail loudly so
 * callers do not silently operate on a no-op.
 */
class Mailer
{
    public function __construct()
    {
        throw new \LogicException(
            __CLASS__ . ' is not yet implemented. See the project README for the current roadmap.'
        );
    }
}
