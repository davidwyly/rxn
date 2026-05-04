<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Routing\Fixture;

use Rxn\Framework\Http\Attribute\Route;
use Rxn\Framework\Http\Attribute\Version;

/**
 * Two versions of the same logical endpoint, plus a same-version
 * collision. The detector must:
 *
 *   - NOT flag `showV1` vs `showV2` — they end up at different
 *     paths once `#[Version]` prefixing is applied.
 *   - DO flag `showV1` vs `showV1Duplicate` — both are `v1` and
 *     have the same pattern; same-version same-pattern is a real
 *     ambiguity.
 *   - Honour class-level `#[Version]` for `index` (registers as
 *     `/v1/widgets`).
 */
#[Version('v1')]
final class VersionedConflictController
{
    #[Route('GET', '/widgets/{id:int}')]
    public function showV1(int $id): array { return []; }

    #[Route('GET', '/widgets/{id:int}')]
    #[Version('v2')]
    public function showV2(int $id): array { return []; }

    #[Route('GET', '/widgets/{id:int}')]
    public function showV1Duplicate(int $id): array { return []; }

    #[Route('GET', '/widgets')]
    public function index(): array { return []; }
}
