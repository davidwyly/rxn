<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Routing\Fixture;

use Rxn\Framework\Http\Attribute\Route;

/**
 * Patterns the detector MUST flag. Each pair is a real
 * runtime-silent ambiguity in the Rxn router (whichever was
 * registered first wins; the other is dead code).
 */
final class ConflictController
{
    // int and slug both accept "123" → conflict
    #[Route('GET', '/items/{id:int}')]
    public function showById(): array { return []; }

    #[Route('GET', '/items/{slug:slug}')]
    public function showBySlug(): array { return []; }
}
