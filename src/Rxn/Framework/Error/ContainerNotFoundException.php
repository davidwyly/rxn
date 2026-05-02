<?php declare(strict_types=1);

namespace Rxn\Framework\Error;

use Psr\Container\NotFoundExceptionInterface;

/**
 * "No such entry in the container." Raised by `Container::get()`
 * when the requested identifier doesn't resolve — typically a
 * class string for a class that doesn't exist or isn't
 * autoloadable.
 *
 * Implements PSR-11's `NotFoundExceptionInterface` so third-party
 * code that catches the standard interface (instead of the
 * Rxn-specific `ContainerException`) routes the missing-entry
 * case correctly. Distinct from the broader `ContainerException`
 * which covers other resolution failures (circular dependency,
 * malformed binding, autowire on a parameter without a type or
 * default).
 *
 * Distinct from `Error\NotFoundException` which is the HTTP-404
 * shape — different concept, different namespace consumers.
 */
class ContainerNotFoundException extends ContainerException implements NotFoundExceptionInterface {}
