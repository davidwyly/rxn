<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Attribute\Fixture;

use Rxn\Framework\Http\Attribute\Route;
use Rxn\Framework\Http\Attribute\Version;

/**
 * Class-level `#[Version]` — every `#[Route]` in the class
 * inherits the version prefix. One method overrides with its
 * own `#[Version]` to prove method-level wins over class-level.
 */
#[Version('v3')]
final class ClassVersionedController
{
    #[Route('GET', '/sprockets')]
    public function index(): array
    {
        return [];
    }

    #[Route('GET', '/sprockets/{id:int}')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }

    // Method-level `#[Version]` — overrides the class-level v3
    // so this one route lands at /v9/sprockets/legacy. Useful for
    // beta endpoints inside an otherwise-stable controller.
    #[Route('GET', '/sprockets/legacy')]
    #[Version('v9')]
    public function legacy(): array
    {
        return [];
    }
}
