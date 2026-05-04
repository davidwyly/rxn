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
 * Deprecation: pass `deprecatedAt` and/or `sunsetAt` (any
 * `DateTimeImmutable`-parseable date string — bare ISO dates
 * like `'2026-01-01'`, full ISO 8601 with timezone, RFC 7231
 * IMF-fixdate, etc. all accepted) and the Scanner attaches a
 * `Versioning\Deprecation` middleware that emits the corresponding
 * RFC 8594 response headers — `Deprecation:` for "this version
 * is on the way out" and `Sunset:` for "after this date, expect
 * 410 Gone or removal."
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

    /**
     * Apply the version prefix to a Route path. Single source of
     * truth for "what URL does this versioned route actually
     * register at" — used by both the runtime Scanner and the
     * static-analysis `ConflictDetector` so the two stay in
     * lockstep on path computation.
     *
     *   '/products/{id:int}' + version 'v1' → '/v1/products/{id:int}'
     *
     * Idempotent: a route already prefixed with the version
     * passes through unchanged. The version label is trimmed of
     * stray slashes (`'v1'`, `'/v1'`, `'v1/'`, `'/v1/'` all
     * normalise to the same `/v1` prefix). An empty trimmed
     * label is rejected — `#[Version('')]` is meaningless.
     */
    public function applyTo(string $path): string
    {
        $label = trim($this->version, '/');
        if ($label === '') {
            throw new \InvalidArgumentException(
                "#[Version] label cannot be empty (got '$this->version')"
            );
        }
        $prefix = '/' . $label;
        if (str_starts_with($path, $prefix . '/') || $path === $prefix) {
            return $path;
        }
        // `$path` is conventionally rooted at `/` — concat is enough.
        return $prefix . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
}
