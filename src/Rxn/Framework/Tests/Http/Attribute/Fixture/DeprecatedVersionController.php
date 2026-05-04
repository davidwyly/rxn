<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute\Fixture;

use Rxn\Framework\Http\Attribute\Route;
use Rxn\Framework\Http\Attribute\Version;

/**
 * `#[Version]` carrying deprecation metadata — the Scanner
 * auto-attaches a `Versioning\Deprecation` middleware so the
 * route's responses gain RFC 8594 `Deprecation:` / `Sunset:`
 * headers without per-handler boilerplate.
 */
final class DeprecatedVersionController
{
    #[Route('GET', '/old/{id:int}', name: 'old.show')]
    #[Version('v1', deprecatedAt: '2026-01-01', sunsetAt: '2026-12-31')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
