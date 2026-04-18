<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

/**
 * Mark a DTO property as required. Absent fields (or null values
 * on non-nullable properties) fail binding with HTTP 422.
 *
 * Purely a marker — the Binder checks the attribute's presence
 * before per-attribute validation runs, so `Required` deliberately
 * doesn't implement `Validates`.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Required
{
}
