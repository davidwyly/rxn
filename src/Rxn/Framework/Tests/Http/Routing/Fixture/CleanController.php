<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Routing\Fixture;

use Rxn\Framework\Http\Attribute\Route;

/**
 * Routes that the detector must NOT flag. Each method-pattern pair
 * is genuinely unambiguous — different verbs, disjoint constraint
 * types, or different segment counts.
 */
final class CleanController
{
    #[Route('GET',  '/users/{id:int}')]
    public function show(): array { return []; }

    #[Route('POST', '/users/{id:int}')]
    public function update(): array { return []; }

    #[Route('GET', '/posts/{id:int}')]
    public function showPost(): array { return []; }

    #[Route('GET', '/posts/{name:alpha}')]
    public function showPostByName(): array { return []; }

    #[Route('GET', '/products')]
    public function listProducts(): array { return []; }

    #[Route('GET', '/products/{id:int}')]
    public function showProduct(): array { return []; }

    #[Route('GET', '/users/me')]
    public function showSelf(): array { return []; }

    #[Route('GET', '/users/{id:int}/orders')]
    public function userOrders(): array { return []; }
}
