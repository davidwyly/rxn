<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Attribute;

/**
 * Mark a controller method (or class) as serving a specific API
 * version. The Scanner reads this and prepends the version label
 * to the registered route's path:
 *
 *   #[Route('GET', '/products/{id:int}')]
 *   #[Version('v1')]
 *   public function showV1(int $id): array { ... }
 *
 *   // → registered as GET /v1/products/{id:int}
 *
 * Class-level `#[Version]` applies to every method-level
 * `#[Route]` in the class. Method-level `#[Version]` wins when
 * both are present.
 *
 * Multiple versions of the same logical endpoint coexist
 * naturally: `/v1/products/{id}` and `/v2/products/{id}` are
 * distinct paths in the Router. The route-conflict detector
 * (`bin/rxn routes:check`) sees them as such and doesn't flag
 * them.
 *
 * Deprecation: pass `deprecatedAt` and/or `sunsetAt` (ISO 8601
 * date strings) and the Scanner attaches a `Versioning\Deprecation`
 * middleware that emits the corresponding RFC 8594 response
 * headers — `Deprecation:` for "this version is on the way out"
 * and `Sunset:` for "after this date, expect 410 Gone or
 * removal."
 *
 *   #[Version('v1', deprecatedAt: '2026-01-01', sunsetAt: '2026-12-31')]
 *
 * The version *label* is the API version, not the URL prefix:
 * pass `'v1'` (Scanner adds the slash). Date-style versions
 * (`'2025-10-15'`) and semantic versions (`'1.0'`) are also
 * accepted — whatever the API contract decided.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class Version
{
    public function __construct(
        public readonly string $version,
        public readonly ?string $deprecatedAt = null,
        public readonly ?string $sunsetAt = null,
    ) {}
}
