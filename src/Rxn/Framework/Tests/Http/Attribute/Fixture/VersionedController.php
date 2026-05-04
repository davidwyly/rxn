<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute\Fixture;

use Rxn\Framework\Http\Attribute\Route;
use Rxn\Framework\Http\Attribute\Version;

/**
 * Method-level `#[Version]` — two methods on the same controller
 * serve the same logical endpoint at different versions. The
 * Scanner registers each at the version-prefixed path; `/v1/widgets`
 * and `/v2/widgets` are distinct paths in the Router.
 */
final class VersionedController
{
    #[Route('GET', '/widgets/{id:int}', name: 'widgets.show.v1')]
    #[Version('v1')]
    public function showV1(int $id): array
    {
        return ['id' => $id, 'version' => 'v1'];
    }

    #[Route('GET', '/widgets/{id:int}', name: 'widgets.show.v2')]
    #[Version('v2')]
    public function showV2(int $id): array
    {
        return ['id' => $id, 'version' => 'v2'];
    }

    // Undecorated route — registered as-is, no version prefix.
    #[Route('GET', '/widgets/health', name: 'widgets.health')]
    public function health(): array
    {
        return ['status' => 'ok'];
    }
}
